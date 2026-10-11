<?php
namespace App\Domain\Travel;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Expenses\FxService;
use App\Domain\Expenses\Models\ExpenseClaim;
use App\Domain\Leave\Accrual\Actors;
use App\Domain\Notifications\NotificationService;
use App\Domain\Operations\{Money, RequestAccess};
use App\Domain\Tenancy\TenantClock;
use App\Domain\Travel\Models\{TravelAdvance, TravelBooking, TravelReconciliation, TravelRequest};
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Travel desk: advance REQUESTS linked to a payroll/finance reference (no money moves here), booking records, and the post-trip reconciliation.
 * Approvals are independent (never the requester) and scoped; finance references and reconciliation need tenant-wide travel.manage and cannot be done on your own trip.
 */
final class TravelDeskService {
    public function __construct(private TravelService $travel, private RequestAccess $access, private DataScope $scope, private TaskService $tasks, private FxService $fx) {}

    private function trip(User $user, string $uuid, bool $lock = false): TravelRequest {
        $q = $this->travel->visible($user)->where('uuid', $uuid); if ($lock) $q->lockForUpdate();
        return $q->firstOrFail();
    }
    private function advance(User $user, string $uuid, bool $lock = false): array {
        $a = TravelAdvance::where('uuid', $uuid); if ($lock) $a->lockForUpdate(); $a = $a->firstOrFail();
        return [$a, $this->trip($user, TravelRequest::whereKey($a->travel_request_id)->value('uuid'))];
    }
    private function manager(User $user): bool { return $user->allows('travel.manage') && $this->scope->hasTenantWide($user, 'travel.manage'); }
    private function invalid(string $m): never { throw ValidationException::withMessages(['travel' => $m]); }
    private function notify(?int $userId, string $title, string $body, string $action): void {
        if ($userId && User::whereKey($userId)->where('active', true)->exists()) app(NotificationService::class)->send($userId, $title, $body, 'operations', $action);
    }

    public function advanceView(TravelAdvance $a, User $user): array {
        $own = $this->access->own($user, $a);
        return ['uuid' => $a->uuid, 'amount' => Money::format($a->amount_minor, $a->minor_units), 'currency' => $a->currency, 'purpose' => $a->purpose, 'status' => $a->status, 'decision_reason' => $a->decision_reason,
            'reference_type' => $a->reference_type, 'finance_reference' => $a->finance_reference, 'referenced_at' => $a->referenced_at?->toIso8601String(),
            'can_review' => $a->status === 'requested' && $this->access->canReview($user, $a, 'travel'), 'can_cancel' => $own && $a->status === 'requested',
            'can_reference' => $a->status === 'approved' && ! $a->finance_reference && $this->manager($user) && ! $own,
            'note' => 'Recorded for tracking only. Payment happens in your payroll or finance system.'];
    }
    public function requestAdvance(User $user, string $tripUuid, array $d): TravelAdvance {
        return DB::transaction(function () use ($user, $tripUuid, $d) {
            $trip = $this->trip($user, $tripUuid, true);
            abort_unless($user->allows('travel.self') && $this->access->own($user, $trip), 403);
            if ($trip->status !== 'approved') $this->invalid('Advances can only be requested for an approved trip.');
            $amount = Money::minor($d['amount'], $trip->minor_units, 'amount');
            if (! $amount) $this->invalid('Enter a positive advance amount.');
            $committed = (int) TravelAdvance::where('travel_request_id', $trip->id)->whereIn('status', ['requested', 'approved'])->sum('amount_minor');
            if ($committed + $amount > $trip->budget_minor) $this->invalid('Advances cannot exceed the approved trip budget.');
            $a = TravelAdvance::create(['travel_request_id' => $trip->id, 'employee_id' => $trip->employee_id, 'created_by' => $user->id, 'currency' => $trip->currency, 'minor_units' => $trip->minor_units,
                'amount_minor' => $amount, 'purpose' => $d['purpose'] ?? null, 'status' => 'requested']);
            $count = 0;
            foreach (User::whereIn('id', Actors::usersWithPermission('travel.approve', [$user->id]))->get() as $reviewer) {
                if (! $this->access->canReview($reviewer, $a, 'travel')) continue;
                $this->tasks->createOnce(['assignee_id' => $reviewer->id, 'title' => 'Review a travel advance request', 'source_module' => 'travel', 'source_entity' => 'advance:'.$a->uuid, 'action' => '/travel/'.$trip->uuid, 'status' => 'open', 'priority' => 'normal']); $count++;
            }
            if (! $count) $this->invalid('No independent reviewer has access to your employee profile. Ask an administrator to configure an approval role.');
            Audit::record('travel.advance_requested', 'travel', 'advance:'.$a->uuid);
            return $a;
        });
    }
    public function decideAdvance(User $user, string $uuid, string $decision, string $reason): TravelAdvance {
        return DB::transaction(function () use ($user, $uuid, $decision, $reason) {
            [$a, $trip] = $this->advance($user, $uuid, true);
            abort_unless($this->access->canReview($user, $a, 'travel'), 403);
            if ($a->status !== 'requested') $this->invalid('Only requested advances can be reviewed.');
            $a->status = $decision === 'approve' ? 'approved' : 'rejected'; $a->reviewed_by = $user->id; $a->reviewed_at = now(); $a->decision_reason = $reason; $a->save();
            $this->tasks->completeBySource('travel', 'advance:'.$a->uuid);
            if ($decision === 'approve') foreach (Actors::usersWithPermission('travel.manage', [$a->created_by]) as $id) {
                $u = User::find($id);
                if ($u && $this->manager($u) && ! $this->access->own($u, $a)) $this->tasks->createOnce(['assignee_id' => $id, 'title' => 'Record the finance reference for an approved travel advance', 'source_module' => 'travel', 'source_entity' => 'advance-ref:'.$a->uuid,
                    'action' => '/travel/'.$trip->uuid, 'status' => 'open', 'priority' => 'normal']);
            }
            $this->notify($a->created_by, 'Travel advance '.($decision === 'approve' ? 'approved' : 'rejected'), 'Your advance request was '.$decision.'d.', '/travel/'.$trip->uuid);
            Audit::record('travel.advance_'.$decision, 'travel', 'advance:'.$a->uuid);
            return $a;
        });
    }
    public function cancelAdvance(User $user, string $uuid): TravelAdvance {
        return DB::transaction(function () use ($user, $uuid) {
            [$a] = $this->advance($user, $uuid, true);
            abort_unless($this->access->own($user, $a) && $user->allows('travel.self'), 403);
            if ($a->status !== 'requested') $this->invalid('Only requested advances can be cancelled.');
            $a->status = 'cancelled'; $a->save(); $this->tasks->completeBySource('travel', 'advance:'.$a->uuid);
            Audit::record('travel.advance_cancelled', 'travel', 'advance:'.$a->uuid);
            return $a;
        });
    }
    /** Links an approved advance to the payroll or finance document that actually pays it. Nothing is paid by this system. */
    public function recordReference(User $user, string $uuid, array $d): TravelAdvance {
        return DB::transaction(function () use ($user, $uuid, $d) {
            [$a] = $this->advance($user, $uuid, true);
            abort_unless($this->manager($user) && ! $this->access->own($user, $a), 403);
            if ($a->status !== 'approved' || $a->finance_reference) $this->invalid('Only approved advances without a reference can be linked.');
            if (TravelAdvance::where('finance_reference', $d['reference'])->exists()) $this->invalid('This reference is already linked to another advance.');
            $a->reference_type = $d['reference_type']; $a->finance_reference = $d['reference']; $a->referenced_by = $user->id; $a->referenced_at = now(); $a->save();
            $this->tasks->completeBySource('travel', 'advance-ref:'.$a->uuid);
            Audit::record('travel.advance_referenced', 'travel', 'advance:'.$a->uuid);
            return $a;
        });
    }

    public function bookingView(TravelBooking $b): array {
        return ['uuid' => $b->uuid, 'kind' => $b->kind, 'vendor' => $b->vendor, 'confirmation_reference' => $b->confirmation_reference, 'starts_on' => $b->starts_on->toDateString(), 'ends_on' => $b->ends_on?->toDateString(),
            'currency' => $b->currency, 'cost' => Money::format($b->cost_minor, $b->minor_units), 'status' => $b->status];
    }
    public function addBooking(User $user, string $tripUuid, array $d): TravelBooking {
        return DB::transaction(function () use ($user, $tripUuid, $d) {
            $trip = $this->trip($user, $tripUuid, true);
            abort_unless(($user->allows('travel.self') && $this->access->own($user, $trip)) || $this->manager($user), 403);
            if ($trip->status !== 'approved') $this->invalid('Bookings can only be recorded for an approved trip.');
            $scale = Money::scale($d['currency']); $cost = isset($d['cost']) && $d['cost'] !== '' ? Money::minor($d['cost'], $scale, 'cost') : 0;
            if (! empty($d['ends_on']) && $d['ends_on'] < $d['starts_on']) $this->invalid('The end date cannot be before the start date.');
            $b = TravelBooking::create(['travel_request_id' => $trip->id, 'created_by' => $user->id, 'kind' => $d['kind'], 'vendor' => $d['vendor'], 'confirmation_reference' => $d['confirmation_reference'] ?? null,
                'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'] ?? null, 'currency' => $d['currency'], 'minor_units' => $scale, 'cost_minor' => $cost, 'status' => 'booked']);
            Audit::record('travel.booking_recorded', 'travel', 'booking:'.$b->uuid);
            return $b;
        });
    }
    public function cancelBooking(User $user, string $uuid): TravelBooking {
        return DB::transaction(function () use ($user, $uuid) {
            $b = TravelBooking::where('uuid', $uuid)->lockForUpdate()->firstOrFail(); $trip = $this->trip($user, TravelRequest::whereKey($b->travel_request_id)->value('uuid'));
            abort_unless(($user->allows('travel.self') && $this->access->own($user, $trip)) || $this->manager($user), 403);
            if ($b->status !== 'booked') $this->invalid('This booking is already cancelled.');
            $b->status = 'cancelled'; $b->cancelled_at = now(); $b->save(); Audit::record('travel.booking_cancelled', 'travel', 'booking:'.$b->uuid);
            return $b;
        });
    }

    /** Live computation (or the stored snapshot once closed). Positive settlement = company owes the employee; negative = employee owes the company. */
    public function reconciliation(User $user, string $tripUuid): array {
        $trip = $this->trip($user, $tripUuid); $scale = $trip->minor_units; $f = fn ($v) => Money::format(abs($v), $scale);
        $stored = TravelReconciliation::where('travel_request_id', $trip->id)->first();
        if ($stored) return ['status' => 'closed', 'currency' => $stored->currency, 'budget' => $f($stored->budget_minor), 'actual' => $f($stored->actual_minor), 'advance' => $f($stored->advance_minor),
            'variance' => ($stored->variance_minor < 0 ? '-' : '').$f($stored->variance_minor), 'settlement' => ($stored->settlement_minor < 0 ? '-' : '').$f($stored->settlement_minor), 'settlement_direction' => $this->direction($stored->settlement_minor),
            'claims' => $stored->claims, 'blockers' => [], 'closed_at' => $stored->closed_at->toIso8601String(), 'can_close' => false];
        $c = $this->compute($trip);
        return ['status' => 'open', 'currency' => $trip->currency, 'budget' => $f($trip->budget_minor), 'actual' => $f($c['actual']), 'advance' => $f($c['advance']), 'variance' => ($c['variance'] < 0 ? '-' : '').$f($c['variance']),
            'settlement' => ($c['settlement'] < 0 ? '-' : '').$f($c['settlement']), 'settlement_direction' => $this->direction($c['settlement']), 'claims' => $c['claims'], 'blockers' => $c['blockers'],
            'can_close' => ! $c['blockers'] && $this->manager($user) && ! $this->access->own($user, $trip)];
    }
    private function direction(int $s): string { return $s > 0 ? 'company_owes_employee' : ($s < 0 ? 'employee_owes_company' : 'settled'); }
    private function compute(TravelRequest $trip): array {
        $blockers = []; $actual = 0; $claims = [];
        if ($trip->status !== 'approved') $blockers[] = 'Only approved trips can be reconciled.';
        if ($trip->ends_on->toDateString() > TenantClock::today()) $blockers[] = 'The trip has not ended yet.';
        foreach (ExpenseClaim::where('travel_request_id', $trip->id)->orderBy('id')->get() as $claim) {
            if (in_array($claim->status, ['draft', 'submitted'])) { $blockers[] = 'Claim "'.$claim->title.'" is still '.$claim->status.'.'; continue; }
            if (! in_array($claim->status, ['approved', 'paid'])) continue;
            $amount = $claim->total_minor; $note = null;
            if ($claim->currency !== $trip->currency) {
                $rate = $this->fx->rateFor($claim->currency, $trip->currency, $trip->ends_on->toDateString(), null);
                if (! $rate) { $blockers[] = 'No FX rate from '.$claim->currency.' to '.$trip->currency.' for claim "'.$claim->title.'".'; continue; }
                $amount = FxService::convert($claim->total_minor, $claim->minor_units, $this->fx->rateValue($rate), $trip->minor_units); $note = 'converted at '.$this->fx->rateValue($rate);
            }
            $actual += $amount; $claims[] = ['uuid' => $claim->uuid, 'title' => $claim->title, 'status' => $claim->status, 'currency' => $claim->currency, 'total' => Money::format($claim->total_minor, $claim->minor_units), 'counted' => Money::format($amount, $trip->minor_units), 'note' => $note];
        }
        $open = TravelAdvance::where('travel_request_id', $trip->id)->get();
        foreach ($open as $a) {
            if ($a->status === 'requested') $blockers[] = 'An advance request is still awaiting review.';
            if ($a->status === 'approved' && ! $a->finance_reference) $blockers[] = 'An approved advance has no payroll or finance reference.';
        }
        $advance = (int) $open->where('status', 'approved')->whereNotNull('finance_reference')->sum('amount_minor');
        return ['actual' => $actual, 'advance' => $advance, 'variance' => $trip->budget_minor - $actual, 'settlement' => $actual - $advance, 'claims' => $claims, 'blockers' => array_values(array_unique($blockers))];
    }
    public function closeReconciliation(User $user, string $tripUuid, ?string $note): array {
        DB::transaction(function () use ($user, $tripUuid, $note) {
            $trip = $this->trip($user, $tripUuid, true);
            abort_unless($this->manager($user) && ! $this->access->own($user, $trip), 403);
            if (TravelReconciliation::where('travel_request_id', $trip->id)->exists()) $this->invalid('This trip is already reconciled.');
            $c = $this->compute($trip);
            if ($c['blockers']) throw ValidationException::withMessages(['travel' => $c['blockers']]);
            TravelReconciliation::create(['travel_request_id' => $trip->id, 'closed_by' => $user->id, 'currency' => $trip->currency, 'minor_units' => $trip->minor_units, 'budget_minor' => $trip->budget_minor, 'actual_minor' => $c['actual'],
                'advance_minor' => $c['advance'], 'variance_minor' => $c['variance'], 'settlement_minor' => $c['settlement'], 'claims' => $c['claims'], 'note' => $note, 'closed_at' => now()]);
            $this->tasks->completeBySource('travel', 'reconcile:'.$trip->uuid);
            $this->notify(Actors::userIdOf($trip->employee_id), 'Trip reconciliation closed', 'Your trip to '.$trip->destination.' has been reconciled.', '/travel/'.$trip->uuid);
            Audit::record('travel.reconciled', 'travel', 'request:'.$trip->uuid);
        });
        return $this->reconciliation($user, $tripUuid);
    }
    public function desk(User $user, string $tripUuid): array {
        $trip = $this->trip($user, $tripUuid);
        $claims = ExpenseClaim::where('travel_request_id', $trip->id)->orderBy('id')->get();
        return ['advances' => TravelAdvance::where('travel_request_id', $trip->id)->orderBy('id')->get()->map(fn ($a) => $this->advanceView($a, $user))->values(),
            'bookings' => TravelBooking::where('travel_request_id', $trip->id)->orderBy('id')->get()->map(fn ($b) => $this->bookingView($b))->values(),
            'claims' => $claims->filter(fn ($c) => $c->status !== 'draft' || $this->access->own($user, $c))->map(fn ($c) => ['uuid' => $c->uuid, 'title' => $c->title, 'status' => $c->status, 'currency' => $c->currency, 'total' => Money::format($c->total_minor, $c->minor_units)])->values(),
            'reconciliation' => $this->reconciliation($user, $tripUuid), 'can_manage' => $this->manager($user) && ! $this->access->own($user, $trip)];
    }
}
