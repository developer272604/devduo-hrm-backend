<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Bulk\BulkAttendanceAction;
use App\Domain\Attendance\Bulk\BulkAttendanceActions;
use App\Domain\Attendance\Models\AttendanceBulkRun;
use App\Domain\Attendance\Models\AttendanceException;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\Attendance\ApplyBulkRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bulk attendance runner: validate -> PREVIEW (no writes) -> confirm with a signed token -> apply per target in its own transaction
 * -> partial-failure report -> audit. The token binds user, tenant, action, the EXACT target set (hash) and the params (hash), expires
 * after 15 minutes and is single use. Above QUEUE_ABOVE targets the apply is queued. Every target is re-checked against the actor's
 * data scope at preview AND at apply; out-of-scope targets are reported exactly like missing ones ("not found").
 */
final class BulkRunner
{
    public const TTL_MINUTES = 15;

    public const QUEUE_ABOVE = 100;

    public const MAX_TARGETS = 5000;

    public function __construct(private DataScope $scope, private RequestSupport $support) {}

    /** @return array<int,array{key:string,label:string,permission:string,target_kind:string,allowed:bool}> */
    public function catalogue(User $actor): array
    {
        return collect(BulkAttendanceActions::all())->map(fn (BulkAttendanceAction $a) => ['key' => $a->key(), 'label' => $a->label(), 'permission' => $a->permission(), 'target_kind' => $a->targetKind(),
            'allowed' => $actor->allows('attendance.bulk') && $actor->allows($a->permission())])->values()->all();
    }

    public function preview(User $actor, string $key, array $params, array $targets, array $filters): array
    {
        $action = $this->action($actor, $key);
        $params = $action->validate($params);
        $normalized = $targets ? $this->normalize($actor, $action, $targets) : $this->expand($actor, $action, $filters);
        if (! $normalized) {
            throw ValidationException::withMessages(['targets' => 'No targets were selected. Choose employees or adjust the filters.']);
        }
        if (count($normalized) > self::MAX_TARGETS) {
            throw ValidationException::withMessages(['targets' => 'A bulk run is limited to '.self::MAX_TARGETS.' targets. Narrow the selection.']);
        }
        $results = [];
        $counts = ['ok' => 0, 'skipped' => 0, 'error' => 0];
        foreach ($normalized as $i => $target) {
            $r = $this->check($actor, $action, $target, $params);
            $counts[$r['status']]++;
            $results[] = ['index' => $i, 'target' => $target['_ref'], 'status' => $r['status'], 'message' => $r['message']];
        }
        $run = AttendanceBulkRun::create(['user_id' => $actor->id, 'action' => $key, 'params' => $params, 'targets' => $normalized, 'targets_hash' => $this->hashTargets($normalized), 'params_hash' => $this->hash($params),
            'status' => 'previewed', 'token_expires_at' => now()->addMinutes(self::TTL_MINUTES), 'total' => count($normalized), 'preview' => $results]);
        Audit::record('previewed', 'attendance', 'bulk:'.$run->uuid, ['action' => $key, 'total' => count($normalized)] + $counts);

        return ['run' => $run->uuid, 'action' => $key, 'label' => $action->label(), 'total' => count($normalized), 'affected' => $counts['ok'], 'counts' => $counts, 'results' => $results,
            'confirmation_token' => $this->sign($run, $actor), 'expires_at' => $run->token_expires_at->toIso8601String(), 'queued_on_apply' => count($normalized) > self::QUEUE_ABOVE];
    }

    public function apply(User $actor, string $token): AttendanceBulkRun
    {
        $run = $this->verify($actor, $token);
        $action = $this->action($actor, $run->action);
        $run->update(['status' => $run->total > self::QUEUE_ABOVE ? 'queued' : 'processing']);
        if ($run->total > self::QUEUE_ABOVE) {
            Audit::record('queued', 'attendance', 'bulk:'.$run->uuid, ['action' => $run->action, 'total' => $run->total]);
            ApplyBulkRun::dispatch((int) app(TenantContext::class)->id, $run->uuid, $actor->id);

            return $run->refresh();
        }

        return $this->execute($run, $actor, $action);
    }

    /** Runs the targets one by one, each in its own transaction. Used inline and by the queued job. */
    public function execute(AttendanceBulkRun $run, User $actor, ?BulkAttendanceAction $action = null): AttendanceBulkRun
    {
        $action ??= $this->action($actor, $run->action);
        $run->update(['status' => 'processing', 'started_at' => now()]);
        $params = ($run->params ?? []) + ['_batch' => $run->uuid];
        $results = [];
        $applied = $skipped = $failed = 0;
        foreach ($run->targets as $i => $target) {
            $ref = $target['_ref'] ?? [];
            try {
                $check = $this->check($actor, $action, $target, $params);
                if ($check['status'] !== 'ok') {
                    $check['status'] === 'skipped' ? $skipped++ : $failed++;
                    $results[] = ['index' => $i, 'target' => $ref, 'status' => $check['status'], 'message' => $check['message']];

                    continue;
                }
                $outcome = DB::transaction(fn () => $action->apply($actor, $this->strip($target), $params));
                $status = $outcome['status'] ?? 'ok';
                $status === 'ok' ? $applied++ : ($status === 'skipped' ? $skipped++ : $failed++);
                $results[] = ['index' => $i, 'target' => $ref, 'status' => $status, 'message' => $outcome['message'] ?? ''];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = ['index' => $i, 'target' => $ref, 'status' => 'error', 'message' => $this->friendly($e)];
            }
        }
        $status = $failed === 0 ? 'completed' : ($applied + $skipped === 0 ? 'failed' : 'partial');
        $run->update(['status' => $status, 'applied' => $applied, 'skipped' => $skipped, 'failed' => $failed, 'results' => $results, 'finished_at' => now()]);
        Audit::record('applied', 'attendance', 'bulk:'.$run->uuid, ['action' => $run->action, 'total' => $run->total, 'applied' => $applied, 'skipped' => $skipped, 'failed' => $failed, 'status' => $status]);

        return $run->refresh();
    }

    // ---- targets -------------------------------------------------------------------------------

    /** Client targets use public UUIDs; they are resolved to internal ids here and only ever within the actor's scope. */
    private function normalize(User $actor, BulkAttendanceAction $action, array $targets): array
    {
        $out = [];
        $seen = [];
        foreach ($targets as $t) {
            if (! is_array($t)) {
                continue;
            }
            $n = $this->resolve($actor, $action->targetKind(), $t);
            $id = json_encode($n);
            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $out[] = $n;
            }
        }

        return $out;
    }

    private function resolve(User $actor, string $kind, array $t): array
    {
        $date = isset($t['date']) && LocalInstant::validDate($t['date']) ? $t['date'] : null;
        $ref = array_filter(['employee' => $t['employee'] ?? null, 'exception' => $t['exception'] ?? null, 'date' => $t['date'] ?? null], fn ($v) => $v !== null);
        if ($kind === 'exception') {
            $exception = isset($t['exception']) ? AttendanceException::query()->where('uuid', (string) $t['exception'])->first() : null;
            $ok = $exception && $this->scope->canAccess($actor, $exception->employee_id, 'attendance.bulk');

            return ['exception_id' => $ok ? $exception->id : null, '_ref' => $ref];
        }
        $employee = isset($t['employee']) ? Employee::query()->where('uuid', (string) $t['employee'])->first() : null;
        $ok = $employee && $this->scope->canAccess($actor, $employee, 'attendance.bulk');
        $n = ['employee_id' => $ok ? $employee->id : null];
        if ($kind === 'employee_date') {
            $n['date'] = $date;
        } else {
            foreach ($t as $k => $v) {
                if (! in_array($k, ['employee', 'exception'], true) && is_scalar($v)) {
                    $n[$k] = $v;
                }
            }
        }

        return $n + ['_ref' => $ref];
    }

    /** Filters -> targets, always constrained to the actor's data scope. */
    private function expand(User $actor, BulkAttendanceAction $action, array $filters): array
    {
        $kind = $action->targetKind();
        if ($kind === 'exception') {
            $q = AttendanceException::query()->whereIn('status', AttendanceException::OPEN_STATES)->orderBy('id');
            $this->scope->constrain($q, $actor, 'attendance.bulk', 'attendance_exceptions.employee_id');
            foreach (['type', 'severity'] as $f) {
                if (! empty($filters[$f])) {
                    $q->where($f, $filters[$f]);
                }
            }
            if (! empty($filters['from'])) {
                $q->where('attendance_date', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $q->where('attendance_date', '<=', $filters['to']);
            }

            return $q->limit(self::MAX_TARGETS + 1)->get()->map(fn ($e) => ['exception_id' => $e->id, '_ref' => ['exception' => $e->uuid]])->all();
        }
        $q = Employee::query()->whereIn('status', Employee::WORKING)->orderBy('employees.id');
        $this->scope->constrain($q, $actor, 'attendance.bulk');
        if (! empty($filters['employees'])) {
            $q->whereIn('uuid', (array) $filters['employees']);
        }
        foreach (['department_id', 'location_id'] as $dimension) {
            if (! empty($filters[$dimension])) {
                $q->whereIn('employees.id', DB::table('employee_assignments')->where('tenant_id', $actor->tenant_id)->where($dimension, (int) $filters[$dimension])
                    ->where('effective_from', '<=', now()->toDateString())->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))->select('employee_id'));
            }
        }
        $employees = $q->limit(self::MAX_TARGETS + 1)->get();
        if ($kind === 'employee') {
            return $employees->map(fn ($e) => ['employee_id' => $e->id, '_ref' => ['employee' => $e->uuid]])->all();
        }
        $from = $filters['from'] ?? ($filters['date'] ?? null);
        $to = $filters['to'] ?? $from;
        if (! LocalInstant::validDate($from) || ! LocalInstant::validDate($to) || $to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 30) {
            throw ValidationException::withMessages(['filters' => 'Give a date (or from/to dates up to 31 days apart) for this action.']);
        }
        $out = [];
        foreach ($employees as $e) {
            for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
                $out[] = ['employee_id' => $e->id, 'date' => $d->toDateString(), '_ref' => ['employee' => $e->uuid, 'date' => $d->toDateString()]];
                if (count($out) > self::MAX_TARGETS) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /** @return array{status:string,message:string} one target validated: scope first, then the action's own preview */
    private function check(User $actor, BulkAttendanceAction $action, array $target, array $params): array
    {
        $kind = $action->targetKind();
        $employeeId = $target['employee_id'] ?? null;
        if ($kind === 'exception') {
            $exception = ! empty($target['exception_id']) ? AttendanceException::query()->find($target['exception_id']) : null;
            $employeeId = $exception?->employee_id;
            if (! $exception) {
                return ['status' => 'error', 'message' => 'Not found.'];
            }
        }
        if (in_array($kind, ['employee', 'employee_date', 'exception'], true) && (! $employeeId || ! $this->scope->canAccess($actor, (int) $employeeId, 'attendance.bulk'))) {
            return ['status' => 'error', 'message' => 'Not found.'];
        }
        if ($kind === 'employee_date' && empty($target['date'])) {
            return ['status' => 'error', 'message' => 'A valid date is required.'];
        }
        try {
            $r = $action->preview($actor, $this->strip($target), $params);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $this->friendly($e)];
        }
        $status = in_array($r['status'] ?? '', ['ok', 'skipped', 'error'], true) ? $r['status'] : 'error';

        return ['status' => $status, 'message' => (string) ($r['message'] ?? '')];
    }

    private function strip(array $target): array
    {
        unset($target['_ref']);

        return $target;
    }

    private function friendly(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return (string) collect($e->errors())->flatten()->first();
        }
        if ($e instanceof HttpException && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        return 'This item could not be processed. It was left unchanged.';
    }

    // ---- authorization and token ---------------------------------------------------------------

    private function action(User $actor, string $key): BulkAttendanceAction
    {
        $action = BulkAttendanceActions::get($key);
        abort_unless($action, 422, 'That bulk action is not available.');
        abort_unless($actor->allows('attendance.bulk') && $actor->allows($action->permission()), 403, 'You do not have permission to run this bulk action.');

        return $action;
    }

    private function sign(AttendanceBulkRun $run, User $actor): string
    {
        $payload = ['run' => $run->uuid, 'u' => $actor->id, 't' => $run->tenant_id, 'a' => $run->action, 'th' => $run->targets_hash, 'ph' => $run->params_hash, 'exp' => $run->token_expires_at->getTimestamp()];
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return $body.'.'.hash_hmac('sha256', $body, $this->key());
    }

    private function verify(User $actor, string $token): AttendanceBulkRun
    {
        $parts = explode('.', $token);
        abort_unless(count($parts) === 2 && hash_equals(hash_hmac('sha256', $parts[0], $this->key()), $parts[1]), 422, 'The confirmation token is not valid. Run the preview again.');
        $p = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        abort_unless(is_array($p) && isset($p['run'], $p['u'], $p['t'], $p['exp']), 422, 'The confirmation token is not valid. Run the preview again.');
        abort_unless($p['u'] === $actor->id && $p['t'] === $actor->tenant_id, 403, 'This confirmation token belongs to someone else.');
        abort_if($p['exp'] < now()->getTimestamp(), 422, 'The confirmation expired. Run the preview again to confirm.');
        $run = AttendanceBulkRun::query()->where('uuid', $p['run'])->first();
        abort_unless($run && $run->user_id === $actor->id, 404, 'That bulk run was not found.');
        abort_unless($run->status === 'previewed', 409, 'This bulk run was already confirmed. Run a new preview to repeat it.');
        // Re-derive the bound hashes from what is stored: the token must describe exactly this target set, action and params.
        abort_unless($p['a'] === $run->action && hash_equals($this->hashTargets($run->targets), (string) $p['th']) && hash_equals($this->hash($run->params ?? []), (string) $p['ph']), 422, 'The selection changed since the preview. Run the preview again.');

        return $run;
    }

    private function key(): string
    {
        return (string) config('app.key');
    }

    private function hashTargets(array $targets): string
    {
        $items = array_map(fn ($t) => json_encode($this->sorted($this->strip($t))), $targets);
        sort($items);

        return hash('sha256', implode("\n", $items));
    }

    private function hash(array $params): string
    {
        return hash('sha256', json_encode($this->sorted($params)));
    }

    private function sorted(array $a): array
    {
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $this->sorted($v);
            }
        }

        return $a;
    }

    public static function newUuid(): string
    {
        return (string) Str::uuid();
    }
}
