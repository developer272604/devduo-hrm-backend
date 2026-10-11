<?php

namespace App\Domain\Leave\Accrual;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Contracts\EntitlementEngine;
use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Expiry\ExpiryService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveAccrualItem;
use App\Domain\Leave\Models\LeaveAccrualRun;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Accrual runs: preview (no ledger writes) -> run (posts) -> errors -> retry.
 *
 * Idempotency is layered: a run is identified by a deterministic run_key (same window + scope => same run row, a completed run
 * is never executed twice); every ledger posting uses key `accrual:{run_key}:{employee}:{type}`; and the calculation itself
 * ignores occurrences already credited (meta.occurrences), so overlapping windows from different runs cannot double-credit.
 * One bad employee never stops the run: the item is recorded as failed (see errors()) and `retry()` reprocesses failed items only.
 */
final class AccrualRunService
{
    public const MAX_WINDOW_DAYS = 732;

    public function __construct(private EntitlementEngine $engine, private AccountService $accounts, private LedgerService $ledger, private ExpiryService $expiry, private DomainEvents $events) {}

    /** @param array{from:string,to:string,leave_type_ids?:array,employee_ids?:array,mode?:string,run_key?:string} $params */
    public function normalize(array $params): array
    {
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if (! $v || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! CarbonImmutable::hasFormat($v, 'Y-m-d')) {
                throw ValidationException::withMessages([$k => ['Use a date in Y-m-d format.']]);
            }
        }
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => ['The end date must not be before the start date.']]);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_WINDOW_DAYS) {
            throw ValidationException::withMessages(['to' => ['Choose a window of at most two years.']]);
        }
        $types = collect($params['leave_type_ids'] ?? [])->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
        $emps = collect($params['employee_ids'] ?? [])->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
        $mode = ($params['mode'] ?? 'manual') === 'scheduled' ? 'scheduled' : 'manual';
        $key = $params['run_key'] ?? 'accrual:'.$from.':'.$to.':'.substr(sha1(json_encode([$types, $emps])), 0, 10);

        return ['from' => $from, 'to' => $to, 'leave_type_ids' => $types, 'employee_ids' => $emps, 'mode' => $mode, 'run_key' => mb_substr($key, 0, 100)];
    }

    public function findOrCreate(array $params, ?User $user = null): LeaveAccrualRun
    {
        $p = $this->normalize($params);
        $run = LeaveAccrualRun::query()->where('run_key', $p['run_key'])->first();
        if ($run) {
            return $run;
        }

        return LeaveAccrualRun::create(['run_key' => $p['run_key'], 'mode' => $p['mode'], 'status' => 'preview', 'period_start' => $p['from'], 'period_end' => $p['to'], 'as_of' => TenantClock::today(),
            'scope' => ['leave_type_ids' => $p['leave_type_ids'], 'employee_ids' => $p['employee_ids']], 'requested_by' => $user?->id]);
    }

    public function preview(array $params, ?User $user = null): LeaveAccrualRun
    {
        $run = $this->findOrCreate($params, $user);
        if (in_array($run->status, ['completed', 'running'], true)) {
            return $run;
        }

        return $this->process($run, false);
    }

    /** Create-or-reuse the run for these parameters and execute it. A completed run is returned unchanged. */
    public function run(array $params, ?User $user = null): LeaveAccrualRun
    {
        $run = $this->findOrCreate($params, $user);

        return $this->execute($run, $user);
    }

    public function execute(LeaveAccrualRun $run, ?User $user = null): LeaveAccrualRun
    {
        if ($run->status === 'completed') {
            return $run;
        }
        $claimed = LeaveAccrualRun::query()->whereKey($run->id)->where(function ($q) {
            $q->whereIn('status', ['preview', 'queued', 'failed', 'partial', 'cancelled'])->orWhere(fn ($x) => $x->where('status', 'running')->where('started_at', '<', now()->subMinutes(30)));
        })->update(['status' => 'running', 'started_at' => now(), 'finished_at' => null, 'error' => null, 'requested_by' => $run->requested_by ?? $user?->id]);
        if (! $claimed) {
            throw new HttpException(409, 'This accrual run is already running.');
        }

        return $this->process($run->refresh(), true);
    }

    /** Reprocess the failed items of a finished run. */
    public function retry(LeaveAccrualRun $run, ?User $user = null): LeaveAccrualRun
    {
        if (! in_array($run->status, ['failed', 'partial'], true)) {
            throw new HttpException(409, 'Only a failed or partial run can be retried.');
        }

        return $this->execute($run, $user);
    }

    /** @return array<int,array<string,mixed>> failed and skipped items with their reason */
    public function errors(LeaveAccrualRun $run, bool $includeSkipped = true): array
    {
        return LeaveAccrualItem::query()->where('run_id', $run->id)->whereIn('status', $includeSkipped ? ['failed', 'skipped'] : ['failed'])->orderBy('id')->get()
            ->map(fn ($i) => ['employee_id' => $i->employee_id, 'leave_type_id' => $i->leave_type_id, 'status' => $i->status, 'reason' => $i->skip_reason])->all();
    }

    // ---- nightly -----------------------------------------------------------------------------------------

    public function nightlyParams(?string $today = null): ?array
    {
        if (! LeaveSettings::get('accrual.nightly_enabled', true)) {
            return null;
        }
        $today ??= TenantClock::today();
        $catch = max(0, (int) LeaveSettings::get('accrual.catch_up_days', 3));

        return ['from' => CarbonImmutable::parse($today)->subDays($catch)->toDateString(), 'to' => $today, 'mode' => 'scheduled', 'run_key' => 'accrual:nightly:'.$today];
    }

    // ---- processing --------------------------------------------------------------------------------------

    private function process(LeaveAccrualRun $run, bool $post): LeaveAccrualRun
    {
        $from = substr((string) $run->getRawOriginal('period_start'), 0, 10);
        $to = substr((string) $run->getRawOriginal('period_end'), 0, 10);
        $scope = $run->scope ?? [];
        $typeIds = $scope['leave_type_ids'] ?? [];
        $empIds = $scope['employee_ids'] ?? [];
        $types = LeaveType::query()->where('active', true)->where('tracks_balance', true)->when($typeIds, fn ($q) => $q->whereIn('id', $typeIds))->orderBy('id')->get();
        $resolver = app(LeavePolicyResolver::class);
        $user = $run->requested_by ? User::query()->find($run->requested_by) : null;

        Employee::query()->whereNotIn('status', ['preboarding', 'archived'])->where('joined_on', '<=', $to)->where(fn ($q) => $q->whereNull('separated_on')->orWhere('separated_on', '>=', $from))
            ->when($empIds, fn ($q) => $q->whereIn('id', $empIds))->chunkById(200, function ($employees) use ($run, $types, $resolver, $from, $to, $post, $user) {
                foreach ($employees as $employee) {
                    $joined = substr((string) $employee->getRawOriginal('joined_on'), 0, 10);
                    foreach ($types as $type) {
                        $probe = max($from, min($joined, $to));
                        if (! $resolver->rulesFor($employee->id, $type->id, $probe)) {
                            continue;
                        }
                        $item = LeaveAccrualItem::query()->where('run_id', $run->id)->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->first();
                        if ($item && $item->status === 'posted') {
                            continue;
                        }
                        $this->processItem($run, $item, $employee, $type, $from, $to, $post, $user);
                    }
                }
            });

        $counts = LeaveAccrualItem::query()->where('run_id', $run->id)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $posted = (int) ($counts['posted'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $skipped = (int) ($counts['skipped'] ?? 0);
        $update = ['total_items' => (int) $counts->sum(), 'posted_items' => $posted, 'skipped_items' => $skipped, 'failed_items' => $failed];
        if ($post) {
            $this->expiry->sync();
            $update += ['status' => $failed === 0 ? 'completed' : (($posted + $skipped) > 0 ? 'partial' : 'failed'), 'finished_at' => now()];
        } else {
            $update += ['status' => 'preview'];
        }
        $run->forceFill($update)->save();
        if ($post) {
            Audit::record('leave_accrual_run', 'leave', 'leave_accrual_run:'.$run->uuid, ['status' => $run->status, 'posted' => $posted, 'skipped' => $skipped, 'failed' => $failed, 'window' => [$from, $to]]);
            $this->events->record(LeaveEvents::ACCRUAL_COMPLETED, ['summary' => 'Leave accrual run '.$run->status, 'actor_id' => $user?->id, 'effective_date' => $to, 'payload' => ['run' => $run->uuid, 'posted' => $posted, 'skipped' => $skipped, 'failed' => $failed]]);
        }

        return $run;
    }

    private function processItem(LeaveAccrualRun $run, ?LeaveAccrualItem $item, Employee $employee, LeaveType $type, string $from, string $to, bool $post, ?User $user): void
    {
        $attrs = ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'run_id' => $run->id];
        try {
            $calc = $this->engine->accrualFor($employee->id, $type->id, $from, $to);
            $stored = $calc;
            foreach ($stored['periods'] ?? [] as $i => $seg) {
                unset($stored['periods'][$i]['rule']);
            }
            if (($calc['skipped'] ?? null) || Units::isZero($calc['units'])) {
                $this->save($item, $attrs, ['status' => 'skipped', 'units' => '0', 'skip_reason' => mb_substr((string) ($calc['skipped'] ?? 'nothing_due'), 0, 250), 'calculation' => $stored]);

                return;
            }
            if (! $post) {
                $this->save($item, $attrs, ['status' => 'preview', 'units' => $calc['units'], 'skip_reason' => null, 'calculation' => $stored]);

                return;
            }
            $entry = null;
            $accountId = null;
            $segments = $calc['periods'] ?? [];
            foreach ($segments as $seg) {
                $account = $this->accounts->ensure($employee->id, $type->id, $seg['effective_date']);
                $accountId = $account->id;
                $key = 'accrual:'.$run->run_key.':'.$employee->id.':'.$type->id.(count($segments) > 1 ? ':'.$seg['period_start'] : '');
                $entry = $this->postSegment($run, $account->id, $seg, $key, $user);
            }
            $this->save($item, $attrs, ['status' => 'posted', 'units' => $calc['units'], 'skip_reason' => null, 'calculation' => $stored, 'account_id' => $accountId, 'ledger_entry_id' => $entry?->id]);
        } catch (\Throwable $e) {
            $message = $e instanceof HttpException || $e instanceof ValidationException ? $e->getMessage() : 'Unexpected error: '.class_basename($e);
            if (! ($e instanceof HttpException || $e instanceof ValidationException)) {
                report($e);
            }
            $this->save($item, $attrs, ['status' => 'failed', 'units' => '0', 'skip_reason' => mb_substr($message, 0, 250)]);
        }
    }

    private function postSegment(LeaveAccrualRun $run, int $accountId, array $seg, string $key, ?User $user)
    {
        $rule = $seg['rule'];
        $effective = $seg['effective_date'];
        $expires = RuleReader::expiryFor($rule, $effective, $seg['period_end']);
        $meta = ['run' => $run->uuid, 'occurrences' => $seg['occurrences'], 'basis' => $rule['basis'], 'rule_version_id' => $rule['version_id'], 'lines' => $seg['lines'], 'period' => [$seg['period_start'], $seg['period_end']]];
        $post = fn (string $date, array $extra = []) => $this->ledger->post(new LedgerPosting($accountId, 'accrual', 'accrued', $seg['units'], $date, $key, expiresOn: $expires, policyVersionId: $rule['version_id'],
            reason: 'Accrual '.$seg['period_start'].' to '.$seg['period_end'], meta: $meta + $extra, createdBy: $user?->id));
        try {
            return $post($effective)->entry;
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 423) {
                throw $e;
            }

            // The accrual date falls in a locked period: credit it in the first open day (today) and keep the trace.
            return $post(TenantClock::today(), ['deferred_by_lock' => true, 'original_effective_date' => $effective])->entry;
        }
    }

    private function save(?LeaveAccrualItem $item, array $attrs, array $values): void
    {
        $item ? $item->forceFill($values)->save() : LeaveAccrualItem::create($attrs + $values);
    }
}
