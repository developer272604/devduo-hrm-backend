<?php

namespace App\Domain\Payroll\Benefits;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Contracts\FormulaEvaluator;
use App\Domain\Payroll\Models\BenefitEnrollmentWindow;
use App\Domain\Payroll\Models\BenefitPlan;
use App\Domain\Payroll\Models\EmployeeBenefitEnrollment;
use App\Domain\Payroll\SelfService\Own;
use App\Domain\People\Models\Employee;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tenant-scoped benefit plans, enrollment windows and maker-checker enrollment decisions. */
final class BenefitService
{
    private const CONTRIBUTION_VARIABLES = [
        'BASE', 'GROSS', 'GROSS_REFERENCE', 'TAXABLE_GROSS',
        'PAYABLE_DAYS', 'WORKING_DAYS', 'PERIOD_DAYS', 'LOP_DAYS',
        'COVERED_DAYS', 'COVERED_PERIOD_DAYS', 'PRORATION_FACTOR',
    ];

    public function plans(): array
    {
        return BenefitPlan::query()->with('enrollmentWindows')->latest('id')->get()->map(fn ($p) => $this->presentPlan($p))->all();
    }

    public function createPlan(User $actor, array $data): BenefitPlan
    {
        abort_unless($actor->allows('payroll.benefits.manage'), 403);
        $data['contributions'] = $this->normalizeContributions($data['contributions'] ?? []);
        $this->validateContributions($data['contributions'] ?? []);
        $plan = BenefitPlan::create($data + ['status' => 'draft']);
        Audit::record('payroll.benefit_plan.created', 'payroll', $plan->uuid);

        return $plan;
    }

    public function activate(User $actor, BenefitPlan $plan): BenefitPlan
    {
        abort_unless($actor->allows('payroll.benefits.manage'), 403);
        abort_if($plan->effective_to && $plan->effective_to < now()->toDateString(), 422, 'An expired plan cannot be activated.');
        $plan->update(['status' => 'active']);
        Audit::record('payroll.benefit_plan.activated', 'payroll', $plan->uuid);

        return $plan;
    }

    public function window(User $actor, BenefitPlan $plan, array $data): BenefitEnrollmentWindow
    {
        abort_unless($actor->allows('payroll.benefits.manage'), 403);
        abort_unless($plan->status === 'active', 409, 'Activate the plan before opening enrollment.');
        abort_if($data['closes_on'] < $data['opens_on'] || $data['effective_on'] < $data['opens_on'], 422, 'Enrollment dates are inconsistent.');

        return BenefitEnrollmentWindow::create(['plan_id' => $plan->id] + $data);
    }

    public function openPlans(User $actor): array
    {
        $employee = Own::employee($actor);
        $today = now($employee->timezone)->toDateString();

        return BenefitPlan::query()->where('status', 'active')->where('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->with(['enrollmentWindows' => fn ($q) => $q->where('opens_on', '<=', $today)->where('closes_on', '>=', $today)])->get()
            ->filter(fn ($p) => $p->eligibleFor($employee))->map(fn ($p) => $this->presentPlan($p))->values()->all();
    }

    public function enroll(User $actor, string $planUuid, array $data): EmployeeBenefitEnrollment
    {
        abort_unless($actor->allows('payroll.benefits.enroll_own'), 403);
        $employee = Own::employee($actor);

        return DB::transaction(function () use ($actor, $employee, $planUuid, $data) {
            $plan = BenefitPlan::query()->where('uuid', $planUuid)->lockForUpdate()->firstOrFail();
            abort_unless($plan->status === 'active' && $plan->eligibleFor($employee), 404);
            $window = $plan->enrollmentWindows()->whereKey($data['window_id'] ?? 0)->whereDate('opens_on', '<=', now($employee->timezone)->toDateString())->whereDate('closes_on', '>=', now($employee->timezone)->toDateString())->first();
            if (! $window) {
                throw ValidationException::withMessages(['window_uuid' => 'Choose an enrollment window that is open today.']);
            }
            if (EmployeeBenefitEnrollment::query()->where('employee_id', $employee->id)->where('plan_id', $plan->id)->whereIn('status', ['pending', 'active', 'termination_pending'])->exists()) {
                throw ValidationException::withMessages(['plan_uuid' => 'You already have a pending or active enrollment for this plan.']);
            }
            $contributions = $plan->contributions ?? [];
            foreach (['employee_fixed', 'employer_fixed'] as $field) {
                if (! preg_match('/^\d{1,12}(?:\.\d{1,4})?$/D', (string) ($contributions[$field] ?? '0'))) {
                    throw ValidationException::withMessages([$field => 'Enter a non-negative decimal amount.']);
                }
            }
            $contributions = $this->normalizeContributions($contributions);
            $this->validateContributions($contributions);
            $dependentUuids = array_values(array_unique($data['dependents'] ?? []));
            if ($dependentUuids && DB::table('employee_dependents')->where('person_id', $employee->person_id)->where('status', 'active')->whereIn('uuid', $dependentUuids)->count() !== count($dependentUuids)) {
                throw ValidationException::withMessages(['dependents' => 'Select only active dependents on your employee profile.']);
            }
            $enrollment = EmployeeBenefitEnrollment::create(['employee_id' => $employee->id, 'plan_id' => $plan->id, 'window_id' => $window->id, 'status' => 'pending', 'created_by' => $actor->id, 'effective_from' => $window->effective_on,
                'employee_contribution' => (string) ($contributions['employee_fixed'] ?? '0'), 'employer_contribution' => (string) ($contributions['employer_fixed'] ?? '0'), 'dependents' => $dependentUuids]);
            Own::requestApproval($actor, 'payroll.benefits.manage', $employee, 'Review benefit enrollment', 'benefit:'.$enrollment->uuid, '/payroll/benefits/enrollments/'.$enrollment->uuid);
            Audit::record('payroll.benefit_enrollment.submitted', 'payroll', $enrollment->uuid, ['plan_uuid' => $plan->uuid]);

            return $enrollment;
        });
    }

    public function decide(User $actor, string $uuid, string $decision): EmployeeBenefitEnrollment
    {
        return DB::transaction(function () use ($actor, $uuid, $decision) {
            $enrollment = EmployeeBenefitEnrollment::query()->with('plan')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($actor->allows('payroll.benefits.manage') && app(DataScope::class)->canAccess($actor, $enrollment->employee_id, 'payroll.benefits.manage'), 404);
            abort_if($enrollment->created_by === $actor->id, 403, 'A different authorized person must review this enrollment.');
            abort_if($enrollment->status !== 'pending', 409, 'This enrollment has already been reviewed.');
            $employee = Employee::query()->findOrFail($enrollment->employee_id);
            $plan = $enrollment->plan;
            abort_if($decision === 'approved' && (! $plan->eligibleFor($employee) || $plan->status !== 'active'), 422, 'The employee is no longer eligible for this active plan.');
            $enrollment->update(['status' => $decision === 'approved' ? 'active' : 'declined']);
            app(TaskService::class)->completeBySource('payroll', 'benefit:'.$enrollment->uuid);
            Audit::record('payroll.benefit_enrollment.'.$decision, 'payroll', $enrollment->uuid);

            return $enrollment->refresh();
        });
    }

    public function requestTermination(User $actor, string $uuid, string $effectiveTo): EmployeeBenefitEnrollment
    {
        abort_unless($actor->allows('payroll.benefits.view_own'), 403);
        $employee = Own::employee($actor);
        abort_if($effectiveTo < now($employee->timezone)->toDateString(), 422, 'Coverage cannot end in the past.');

        return DB::transaction(function () use ($actor, $employee, $uuid, $effectiveTo) {
            $enrollment = EmployeeBenefitEnrollment::query()->where('uuid', $uuid)->where('employee_id', $employee->id)->lockForUpdate()->firstOrFail();
            abort_unless($enrollment->status === 'active', 409, 'Only active enrollments can request termination.');
            abort_if($effectiveTo < substr((string) $enrollment->effective_from, 0, 10), 422, 'Coverage cannot end before it starts.');
            $enrollment->update(['status' => 'termination_pending', 'effective_to' => $effectiveTo, 'termination_requested_by' => $actor->id, 'termination_decided_by' => null]);
            $entity = 'benefit_termination:'.$enrollment->uuid;
            Own::requestApproval($actor, 'payroll.benefits.manage', $employee, 'Review benefit termination', $entity, '/payroll/benefits');
            Audit::record('payroll.benefit_termination.requested', 'payroll', $enrollment->uuid, ['effective_to' => $effectiveTo]);

            return $enrollment->refresh();
        });
    }

    public function decideTermination(User $actor, string $uuid, string $decision): EmployeeBenefitEnrollment
    {
        return DB::transaction(function () use ($actor, $uuid, $decision) {
            $enrollment = EmployeeBenefitEnrollment::query()->with('plan')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($actor->allows('payroll.benefits.manage') && app(DataScope::class)->canAccess($actor, $enrollment->employee_id, 'payroll.benefits.manage'), 404);
            abort_if($enrollment->termination_requested_by === $actor->id, 403, 'A different authorized person must review this termination.');
            abort_if($enrollment->status !== 'termination_pending', 409, 'This termination has already been reviewed.');
            $enrollment->update($decision === 'approved'
                ? ['status' => 'ended', 'termination_decided_by' => $actor->id]
                : ['status' => 'active', 'effective_to' => null, 'termination_requested_by' => null, 'termination_decided_by' => $actor->id]);
            app(TaskService::class)->completeBySource('payroll', 'benefit_termination:'.$enrollment->uuid);
            Audit::record('payroll.benefit_termination.'.$decision, 'payroll', $enrollment->uuid, ['effective_to' => $decision === 'approved' ? $enrollment->effective_to : null]);

            return $enrollment->refresh();
        });
    }

    public function ownEnrollments(User $actor): array
    {
        $employee = Own::employee($actor);

        return EmployeeBenefitEnrollment::query()->with('plan')->where('employee_id', $employee->id)->latest('id')->get()->map(fn ($e) => ['uuid' => $e->uuid, 'plan' => $e->plan->name, 'category' => $e->plan->category,
            'status' => $e->status, 'effective_from' => substr((string) $e->effective_from, 0, 10), 'effective_to' => $e->effective_to ? substr((string) $e->effective_to, 0, 10) : null])->all();
    }

    public function enrollments(User $actor): array
    {
        abort_unless($actor->allows('payroll.benefits.manage'), 403);
        $ids = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.benefits.manage')->select('employees.id');

        return EmployeeBenefitEnrollment::query()->with(['employee.person', 'plan'])->whereIn('employee_id', $ids)->latest('id')->paginate(50)->through(fn ($e) => ['uuid' => $e->uuid, 'employee' => $e->employee->person->display_name,
            'employee_uuid' => $e->employee->uuid, 'plan' => $e->plan->name, 'status' => $e->status, 'effective_from' => substr((string) $e->effective_from, 0, 10), 'effective_to' => $e->effective_to ? substr((string) $e->effective_to, 0, 10) : null])->items();
    }

    public function presentEnrollment(EmployeeBenefitEnrollment $e): array
    {
        return ['uuid' => $e->uuid, 'status' => $e->status, 'employee_uuid' => $e->employee?->uuid, 'plan_uuid' => $e->plan?->uuid];
    }

    public function presentPlan(BenefitPlan $p): array
    {
        return ['uuid' => $p->uuid, 'code' => $p->code, 'name' => $p->name, 'category' => $p->category, 'provider' => $p->provider, 'country' => $p->country, 'currency' => $p->currency,
            'eligibility' => $p->eligibility, 'contributions' => $p->contributions, 'tax_treatment' => $p->tax_treatment, 'component_code' => $p->component_code,
            'effective_from' => substr((string) $p->effective_from, 0, 10), 'effective_to' => $p->effective_to ? substr((string) $p->effective_to, 0, 10) : null, 'status' => $p->status,
            'windows' => $p->enrollmentWindows->map(fn ($w) => ['uuid' => $w->uuid, 'name' => $w->name, 'opens_on' => substr((string) $w->opens_on, 0, 10), 'closes_on' => substr((string) $w->closes_on, 0, 10), 'effective_on' => substr((string) $w->effective_on, 0, 10)])->all()];
    }

    private function validateContributions(array $contributions): void
    {
        $messages = [];
        foreach (['employee_formula', 'employer_formula'] as $field) {
            $formula = trim((string) ($contributions[$field] ?? ''));
            if ($formula === '') {
                continue;
            }
            $errors = app(FormulaEvaluator::class)->validate($formula, self::CONTRIBUTION_VARIABLES);
            if ($errors) {
                $messages['contributions.'.$field] = $errors[0];
            }
        }
        if ($messages) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function normalizeContributions(array $contributions): array
    {
        foreach (['employee_formula', 'employer_formula'] as $field) {
            if (isset($contributions[$field]) && trim((string) $contributions[$field]) !== '') {
                $contributions[$field] = strtoupper(trim((string) $contributions[$field]));
            }
        }

        return $contributions;
    }
}
