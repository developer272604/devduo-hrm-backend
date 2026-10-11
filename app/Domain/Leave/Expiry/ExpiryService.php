<?php

namespace App\Domain\Leave\Expiry;

use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Accrual\Actors;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveExpiry;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\Notifications\NotificationService;
use App\Domain\Tenancy\TenantClock;
use Carbon\CarbonImmutable;

/**
 * Expiry of credit lots (leave_ledger_entries.expires_on) and the alerts that precede it.
 *
 *   sync()    makes sure every expiring lot has a leave_expiries row (lots can be created by accrual, carry-forward, comp-off, adjustments)
 *   process() posts an `expiry` debit for what is still unused after the lot's last valid day; the debit allocates to the lot so
 *             source-to-use traces stay exact. Idempotent per lot (key expiry:{entry id}).
 *   alerts()  in-app notification + domain event when a lot enters a "notify_days_before" window (each threshold fires once).
 */
final class ExpiryService
{
    public function __construct(private LedgerService $ledger, private BalanceService $balances, private NotificationService $notifications, private DomainEvents $events) {}

    public function sync(): int
    {
        $created = 0;
        $have = LeaveExpiry::query()->pluck('source_entry_id')->all();
        $lots = LeaveLedgerEntry::query()->whereNotNull('expires_on')->where('units', '>', 0)->whereIn('bucket', BalanceService::CREDIT_BUCKETS)->whereNull('reverses_entry_id')
            ->when($have, fn ($q) => $q->whereNotIn('id', $have))->orderBy('id')->get();
        foreach ($lots as $lot) {
            LeaveExpiry::create(['account_id' => $lot->account_id, 'employee_id' => $lot->employee_id, 'leave_type_id' => $lot->leave_type_id, 'source_entry_id' => $lot->id,
                'units' => Units::normalize($lot->getRawOriginal('units')), 'expires_on' => substr((string) $lot->getRawOriginal('expires_on'), 0, 10), 'status' => 'scheduled']);
            $created++;
        }

        return $created;
    }

    /**
     * Expires every lot whose last valid day is before $asOf.
     *
     * @return array{expired:int,units:string,already_used:int,cancelled:int,items:array<int,array<string,mixed>>}
     */
    public function process(?string $asOf = null, ?array $employeeIds = null, bool $dryRun = false): array
    {
        $asOf ??= TenantClock::today();
        $this->sync();
        $out = ['expired' => 0, 'units' => '0.0000', 'already_used' => 0, 'cancelled' => 0, 'items' => []];
        $rows = LeaveExpiry::query()->whereIn('status', ['scheduled', 'notified'])->where('expires_on', '<', $asOf)->when($employeeIds, fn ($q) => $q->whereIn('employee_id', $employeeIds))->orderBy('expires_on')->orderBy('id')->get();
        foreach ($rows as $row) {
            $source = LeaveLedgerEntry::query()->find($row->source_entry_id);
            $reversed = $source && LeaveLedgerEntry::query()->where('reverses_entry_id', $source->id)->exists();
            if (! $source || $reversed) {
                $dryRun || $row->forceFill(['status' => 'cancelled'])->save();
                $out['cancelled']++;

                continue;
            }
            $effective = CarbonImmutable::parse(substr((string) $row->getRawOriginal('expires_on'), 0, 10))->addDay()->toDateString();
            $lot = collect($this->balances->lots($row->account_id, $effective))->firstWhere('entry_id', $source->id);
            $remaining = $lot['remaining'] ?? '0.0000';
            $available = $this->balances->balance($row->account_id, $effective)->available;
            $amount = Units::min($remaining, Units::max('0', $available));
            $item = ['expiry_id' => $row->id, 'employee_id' => $row->employee_id, 'leave_type_id' => $row->leave_type_id, 'source_entry_id' => $source->id, 'expires_on' => $row->getRawOriginal('expires_on'),
                'units' => $amount, 'remaining_in_lot' => $remaining];
            if (Units::isZero($amount)) {
                $dryRun || $row->forceFill(['status' => 'expired'])->save();
                $out['already_used']++;
                $out['items'][] = $item + ['result' => 'fully_used'];

                continue;
            }
            if (! $dryRun) {
                $posted = $this->ledger->post(new LedgerPosting($row->account_id, 'expiry', 'expired', Units::neg($amount), $effective, 'expiry:'.$source->id, sourceEntryId: $source->id,
                    reason: 'Unused leave expired', meta: ['source_entry_id' => $source->id, 'expires_on' => $item['expires_on'], 'lot_remaining' => $remaining, 'available' => $available, 'system' => true],
                    ignoreLock: true, allowClosed: true, allocations: [['source_entry_id' => $source->id, 'units' => $amount]]));
                $row->forceFill(['status' => 'expired', 'expiry_entry_id' => $posted->entry->id])->save();
            }
            $out['expired']++;
            $out['units'] = Units::add($out['units'], $amount);
            $out['items'][] = $item + ['result' => 'expired'];
        }

        return $out;
    }

    /** @return array{notified:int} */
    public function alerts(?string $today = null): array
    {
        $today ??= TenantClock::today();
        $thresholds = collect((array) LeaveSettings::get('expiry.notify_days_before', [30, 7]))->map(fn ($d) => (int) $d)->filter(fn ($d) => $d > 0)->sort()->values()->all();
        if (! $thresholds) {
            return ['notified' => 0];
        }
        $this->sync();
        $notified = 0;
        $horizon = CarbonImmutable::parse($today)->addDays(max($thresholds))->toDateString();
        $rows = LeaveExpiry::query()->whereIn('status', ['scheduled', 'notified'])->where('expires_on', '>=', $today)->where('expires_on', '<=', $horizon)->orderBy('expires_on')->get();
        foreach ($rows as $row) {
            $left = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse(substr((string) $row->getRawOriginal('expires_on'), 0, 10)));
            $target = collect($thresholds)->first(fn ($t) => $t >= $left);
            if ($target === null || ($row->notified_days !== null && (int) $row->notified_days <= $target)) {
                continue;
            }
            $lot = collect($this->balances->lots($row->account_id, $today))->firstWhere('entry_id', $row->source_entry_id);
            if (! $lot || Units::isZero($lot['remaining'])) {
                continue;
            }
            $type = LeaveType::query()->find($row->leave_type_id);
            if ($userId = Actors::userIdOf($row->employee_id)) {
                $this->notifications->send($userId, 'Leave balance expiring', $lot['remaining'].' day(s) of '.($type?->name ?? 'leave').' expire on '.$lot['expires_on'].'.', 'leave', '/leave');
            }
            $this->events->record(LeaveEvents::BALANCE_EXPIRING, ['employee' => $row->employee_id, 'summary' => 'Leave balance expiring soon', 'effective_date' => $lot['expires_on'], 'actor_id' => null,
                'payload' => ['leave_type' => $type?->uuid, 'units' => $lot['remaining'], 'expires_on' => $lot['expires_on'], 'days_left' => $left]]);
            $row->forceFill(['status' => 'notified', 'notified_at' => now(), 'notified_days' => $target])->save();
            $notified++;
        }

        return ['notified' => $notified];
    }
}
