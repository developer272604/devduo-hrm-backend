<?php

namespace App\Domain\Performance;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\Performance\Models\{Cycle, Response, Review};
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Review cycle lifecycle (draft -> published -> open -> calibration -> closed), effective-dated eligibility and idempotent review generation. */
final class CycleService
{
    public function __construct(private PerformanceAccess $access, private DomainEvents $events) {}

    public function publish(Cycle $c): Cycle
    {
        if ($c->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only draft cycles can be published.']);
        if (! $c->template || ! $c->template->active) throw ValidationException::withMessages(['template' => 'The cycle needs an active template.']);
        $c->update(['status' => 'published']);
        Audit::record('cycle.published', 'performance', 'cycle:'.$c->uuid);

        return $c;
    }

    /** Opens a published cycle and generates reviews for everyone eligible. Re-running on an open cycle only adds newly eligible people. */
    public function launch(Cycle $c): array
    {
        if (! in_array($c->status, ['published', 'open'], true)) throw ValidationException::withMessages(['status' => 'Publish the cycle before launching it.']);
        $stats = DB::transaction(function () use ($c) {
            DB::table('tenants')->where('id', $c->tenant_id)->lockForUpdate()->first();
            $s = ['created' => 0, 'existing' => 0];
            foreach ($this->eligible($c) as $e) $this->generate($c, $e) ? $s['created']++ : $s['existing']++;
            if ($c->status === 'published') $c->update(['status' => 'open', 'opened_at' => now()]);
            Audit::record('cycle.launched', 'performance', 'cycle:'.$c->uuid, $s);

            return $s;
        });
        $this->events->record('PerformanceCycleOpened', ['summary' => 'Performance cycle "'.$c->name.'" opened', 'subject' => ['performance_cycle', $c->id], 'payload' => ['cycle' => $c->uuid]]);

        return $stats;
    }

    public function referenceDate(Cycle $c): string
    {
        $today = TenantClock::today();

        return $today >= $c->start_date->toDateString() && $today <= $c->end_date->toDateString() ? $today : ($today > $c->end_date->toDateString() ? $c->end_date->toDateString() : $c->start_date->toDateString());
    }

    /** Employees eligible as of the reference date: working status, tenure, and effective-dated organization filters. */
    public function eligible(Cycle $c)
    {
        $f = $c->eligibility ?? []; $ref = $this->referenceDate($c);
        $q = Employee::query()->whereIn('status', array_diff(Employee::WORKING, ['suspended']))->where('joined_on', '<=', $ref);
        if (! empty($f['min_tenure_days'])) $q->where('joined_on', '<=', now()->parse($ref)->subDays((int) $f['min_tenure_days'])->toDateString());
        if (! empty($f['exclude_probation'])) $q->where('status', '!=', 'probation');
        $cols = ['legal_entity_ids' => 'legal_entity_id', 'department_ids' => 'department_id', 'employee_type_ids' => 'employee_type_id', 'location_ids' => 'location_id'];
        $filters = array_filter(array_intersect_key($f, $cols));
        if ($filters) {
            $q->whereExists(function ($w) use ($filters, $cols, $ref) {
                $w->from('employee_assignments')->whereColumn('employee_assignments.employee_id', 'employees.id')->where('effective_from', '<=', $ref)
                    ->where(fn ($x) => $x->whereNull('effective_to')->orWhere('effective_to', '>=', $ref));
                foreach ($filters as $k => $ids) $w->whereIn($cols[$k], array_map('intval', (array) $ids));
            });
        }

        return $q->orderBy('id')->get();
    }

    /** Creates the review + self / manager / skip-level responses for one employee. Returns false when it already existed. */
    private function generate(Cycle $c, Employee $e): bool
    {
        if (Review::where('cycle_id', $c->id)->where('employee_id', $e->id)->exists()) return false;
        $ref = $this->referenceDate($c);
        $manager = $this->access->managerOf($e, $ref);
        $a = $this->access->assignmentRow($e->id, $ref);
        $review = Review::create(['cycle_id' => $c->id, 'employee_id' => $e->id, 'manager_employee_id' => $manager?->id, 'department_id' => $a?->department_id, 'legal_entity_id' => $a?->legal_entity_id, 'status' => 'in_progress']);
        $w = $c->template->reviewer_weights;
        $add = function (string $type, ?Employee $who, ?string $due) use ($review, $c) {
            $r = Response::create(['review_id' => $review->id, 'reviewer_type' => $type, 'reviewer_employee_id' => $who?->id, 'reviewer_user_id' => $who ? $this->access->userIdOf($who) : null, 'due_on' => $due ?? $c->end_date->toDateString()]);
            $this->task($r, $review, $c);

            return $r;
        };
        if (($w['self'] ?? 0) > 0) $add('self', $e, $c->self_due?->toDateString());
        if (($w['manager'] ?? 0) > 0) $add('manager', $manager, $c->manager_due?->toDateString());
        if (($w['skip'] ?? 0) > 0 && $manager && ($skip = $this->access->managerOf($manager, $ref))) $add('skip', $skip, $c->manager_due?->toDateString());

        return true;
    }

    public function task(Response $r, Review $review, Cycle $c): void
    {
        if (! $r->reviewer_user_id) return;
        $subject = Employee::with('person')->find($review->employee_id);
        $name = trim(($subject->person->legal_first_name ?? '').' '.($subject->person->legal_last_name ?? ''));
        $title = match ($r->reviewer_type) { 'self' => 'Complete your self review', 'manager' => 'Write the manager review for '.$name, 'skip' => 'Add skip-level input for '.$name, default => 'Give peer feedback for '.$name };
        app(TaskService::class)->createOnce(['title' => $title, 'description' => 'Cycle: '.$c->name.'. Due '.($r->due_on?->toDateString() ?? 'soon').'.', 'assignee_id' => $r->reviewer_user_id, 'source_module' => 'performance',
            'source_entity' => 'review-response:'.$r->uuid, 'action' => '/performance/reviews', 'status' => 'open', 'priority' => 'normal']);
    }
}
