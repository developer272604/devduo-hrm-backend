<?php
namespace App\Domain\Travel;
use App\Domain\Travel\Models\TravelRequest;
use App\Domain\Operations\{Money, RequestAccess};
use App\Domain\Audit\Audit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TravelService {
    public function __construct(public RequestAccess $access) {}
    public function visible(User $user) { return $this->access->visible(TravelRequest::query(), $user, 'travel'); }
    public function create(User $user, array $data): TravelRequest {
        $employeeId = $this->access->ownEmployee($user, 'travel'); $scale = Money::scale($data['currency']);
        $budget = Money::minor($data['budget'], $scale, 'budget');
        if (! $budget) throw ValidationException::withMessages(['budget' => 'Enter a positive estimated budget.']);
        return DB::transaction(function () use ($data, $employeeId, $user, $scale, $budget) {
            $r = TravelRequest::create(collect($data)->except('budget')->all() + ['employee_id' => $employeeId, 'created_by' => $user->id, 'minor_units' => $scale, 'budget_minor' => $budget]);
            Audit::record('travel.created', 'travel', 'request:'.$r->uuid); return $r;
        });
    }
    public function present(TravelRequest $r, User $user): array {
        return ['itinerary' => $r->itinerary ?? [], 'itinerary_version' => $r->itinerary_version, 'uuid' => $r->uuid, 'purpose' => $r->purpose, 'destination' => $r->destination, 'starts_on' => $r->starts_on->toDateString(), 'ends_on' => $r->ends_on->toDateString(),
            'timezone' => $r->timezone, 'currency' => $r->currency, 'budget' => Money::format($r->budget_minor, $r->minor_units), 'status' => $r->status, 'decision_reason' => $r->decision_reason,
            'can_submit' => $r->status === 'draft' && $this->access->own($user, $r), 'can_review' => $r->status === 'submitted' && $this->access->canReview($user, $r, 'travel'),
            'can_cancel' => in_array($r->status, ['draft', 'submitted', 'approved']) && $this->access->own($user, $r)];
    }
    public function itinerary(User $user, string $uuid, array $data): TravelRequest {
        return DB::transaction(function () use ($user, $uuid, $data) {
            $r = $this->visible($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($user->allows('travel.self') && $this->access->own($user, $r), 403);
            if ($r->status !== 'draft') $this->invalid('Only draft itineraries can be changed.');
            abort_if($r->itinerary_version !== (int) $data['version'], 409, 'The itinerary changed. Reload before saving.');
            $previous = null;
            foreach ($data['legs'] as $leg) {
                $departure = \Carbon\CarbonImmutable::parse($leg['departure']);
                $arrival = \Carbon\CarbonImmutable::parse($leg['arrival']);
                foreach (['departure' => $departure, 'arrival' => $arrival] as $key => $instant) {
                    if ($instant->setTimezone($leg[$key.'_timezone'])->format('Y-m-d\TH:i:sP') !== $leg[$key]) $this->invalid('Each timestamp offset must match its named timezone.');
                    $date = $instant->setTimezone($r->timezone)->toDateString();
                    if ($date < $r->starts_on->toDateString() || $date > $r->ends_on->toDateString()) $this->invalid('Leg dates must fall within the trip dates in the trip timezone.');
                }
                if ($arrival->lessThanOrEqualTo($departure) || ($previous && $departure->lessThan($previous))) $this->invalid('Legs must be chronological, with arrival after departure and no overlap.');
                $previous = $arrival;
            }
            $r->itinerary = $data['legs']; $r->itinerary_version++; $r->save();
            Audit::record('travel.itinerary.updated', 'travel', 'request:'.$r->uuid); return $r;
        });
    }
    public function action(User $user, string $uuid, array $data): TravelRequest {
        return DB::transaction(function () use ($user, $uuid, $data) {
            $r = $this->visible($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail(); $action = $data['action'];
            if (in_array($action, ['submit', 'cancel'])) {
                abort_unless($user->allows('travel.self') && $this->access->own($user, $r), 403);
                if ($action === 'submit') {
                    if ($r->status !== 'draft') $this->invalid('Only draft requests can be submitted.');
                    $this->access->reviewTasks($r, 'travel'); $r->status = 'submitted'; $r->submitted_at = now();
                } else {
                    if (! in_array($r->status, ['draft', 'submitted', 'approved'])) $this->invalid('This request cannot be cancelled.');
                    $r->status = 'cancelled'; $r->decision_reason = $data['reason']; $this->access->completeTasks($r, 'travel');
                }
            } else {
                abort_unless($this->access->canReview($user, $r, 'travel'), 403);
                if ($r->status !== 'submitted') $this->invalid('Only submitted requests can be reviewed.');
                $r->status = $action === 'approve' ? 'approved' : 'rejected'; $r->reviewed_by = $user->id; $r->reviewed_at = now(); $r->decision_reason = $data['reason'];
                $this->access->completeTasks($r, 'travel');
            }
            $r->save(); Audit::record('travel.'.$action, 'travel', 'request:'.$r->uuid); return $r;
        });
    }
    private function invalid(string $message): never { throw ValidationException::withMessages(['travel' => $message]); }
}
