<?php

namespace App\Http\Controllers\Payroll\Benefits;

use App\Domain\Payroll\Benefits\BenefitService;
use App\Domain\Payroll\Models\BenefitPlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BenefitController
{
    public function plans(BenefitService $service)
    {
        return ['data' => $service->plans()];
    }

    public function createPlan(Request $request, BenefitService $service)
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Z][A-Z0-9_]{1,39}$/', Rule::unique('benefit_plans', 'code')->where('tenant_id', $request->user()->tenant_id)], 'name' => 'required|string|max:160', 'category' => ['required', Rule::in(['insurance', 'meal', 'transport', 'housing', 'wellness', 'retirement', 'flexible', 'custom'])],
            'provider' => 'nullable|array', 'country' => 'nullable|regex:/^[A-Z]{2}$/', 'currency' => 'nullable|regex:/^[A-Z]{3}$/', 'eligibility' => 'nullable|array', 'eligibility.worker_types' => 'nullable|array', 'eligibility.worker_types.*' => 'string|max:30',
            'eligibility.countries' => 'nullable|array', 'eligibility.countries.*' => 'regex:/^[A-Z]{2}$/', 'eligibility.legal_entity_ids' => 'nullable|array', 'eligibility.legal_entity_ids.*' => 'integer', 'eligibility.min_tenure_days' => 'nullable|integer|min:0|max:36500',
            'contributions' => 'nullable|array', 'contributions.employee_fixed' => ['nullable', 'regex:/^\d{1,12}(?:\.\d{1,4})?$/D'], 'contributions.employer_fixed' => ['nullable', 'regex:/^\d{1,12}(?:\.\d{1,4})?$/D'],
            'tax_treatment' => 'nullable|string|max:40', 'component_code' => 'nullable|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from']);

        return response()->json($service->presentPlan($service->createPlan($request->user(), $data)), 201);
    }

    public function activate(Request $request, string $uuid, BenefitService $service)
    {
        return $service->presentPlan($service->activate($request->user(), BenefitPlan::query()->where('uuid', $uuid)->with('enrollmentWindows')->firstOrFail()));
    }

    public function window(Request $request, string $uuid, BenefitService $service)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'opens_on' => 'required|date_format:Y-m-d', 'closes_on' => 'required|date_format:Y-m-d', 'effective_on' => 'required|date_format:Y-m-d']);
        $plan = BenefitPlan::query()->where('uuid', $uuid)->firstOrFail();
        $window = $service->window($request->user(), $plan, $data);

        return response()->json(['uuid' => $window->uuid, 'plan_uuid' => $plan->uuid], 201);
    }

    public function openPlans(Request $request, BenefitService $service)
    {
        abort_unless($request->user()->allows('payroll.benefits.view_own') || $request->user()->allows('payroll.benefits.enroll_own'), 403);

        return ['data' => $service->openPlans($request->user())];
    }

    public function enroll(Request $request, string $uuid, BenefitService $service)
    {
        $data = $request->validate(['window_uuid' => 'required|uuid', 'dependents' => 'nullable|array|max:20', 'dependents.*' => 'uuid']);
        $plan = BenefitPlan::query()->where('uuid', $uuid)->firstOrFail();
        $data['window_id'] = $plan->enrollmentWindows()->where('uuid', $data['window_uuid'])->value('id');
        abort_unless($data['window_id'], 404);

        return response()->json($service->presentEnrollment($service->enroll($request->user(), $uuid, $data)), 201);
    }

    public function own(Request $request, BenefitService $service)
    {
        abort_unless($request->user()->allows('payroll.benefits.view_own'), 403);

        return ['data' => $service->ownEnrollments($request->user())];
    }

    public function requestTermination(Request $request, string $uuid, BenefitService $service)
    {
        $data = $request->validate(['effective_to' => 'required|date_format:Y-m-d']);

        return $service->presentEnrollment($service->requestTermination($request->user(), $uuid, $data['effective_to']));
    }

    public function enrollments(Request $request, BenefitService $service)
    {
        return ['data' => $service->enrollments($request->user())];
    }

    public function decide(Request $request, string $uuid, BenefitService $service)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);

        return $service->presentEnrollment($service->decide($request->user(), $uuid, $data['decision']));
    }

    public function decideTermination(Request $request, string $uuid, BenefitService $service)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);

        return $service->presentEnrollment($service->decideTermination($request->user(), $uuid, $data['decision']));
    }
}
