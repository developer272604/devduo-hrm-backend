<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    /** Public price list (active plans only) for the sign-up and trial-ended screens. */
    public function publicIndex()
    {
        return response()->json(Plan::where('active', true)->orderBy('sort')->orderBy('id')->get(['code', 'name', 'description', 'monthly_price', 'currency', 'modules']));
    }

    public function index()
    {
        $counts = Tenant::where('is_platform', false)->selectRaw('plan, count(*) as n')->groupBy('plan')->pluck('n', 'plan');

        return response()->json([
            'plans' => Plan::orderBy('sort')->orderBy('id')->get()->map(fn ($p) => $p->toArray() + ['companies' => (int) ($counts[$p->code] ?? 0)]),
            'available_modules' => WorkspaceProvisioner::MODULES,
        ]);
    }

    private function rules(?int $id = null): array
    {
        return [
            'code' => [$id ? 'prohibited' : 'required', 'alpha_dash', 'max:40', Rule::unique('plans', 'code')],
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:500',
            'monthly_price' => 'required|numeric|min:0|max:99999999',
            'currency' => 'nullable|string|size:3',
            'modules' => 'required|array|min:1',
            'modules.*' => ['string', Rule::in(WorkspaceProvisioner::MODULES)],
            'active' => 'boolean',
            'sort' => 'nullable|integer|min:0|max:65000',
        ];
    }

    public function store(Request $r)
    {
        $data = $r->validate($this->rules());
        $data['code'] = strtolower($data['code']);
        $data['currency'] = strtoupper($data['currency'] ?? 'INR');
        $plan = Plan::create($data);
        Audit::record('plan_created', 'owner', 'plan:'.$plan->code, ['price' => $plan->monthly_price]);

        return response()->json($plan, 201);
    }

    public function update(Request $r, int $id)
    {
        $plan = Plan::findOrFail($id);
        $data = $r->validate($this->rules($id));
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        $plan->update($data);
        Audit::record('plan_updated', 'owner', 'plan:'.$plan->code, ['price' => $plan->monthly_price]);

        return response()->json($plan);
    }

    /** Plans in use are only retired (hidden from the price list), never deleted. */
    public function destroy(int $id)
    {
        $plan = Plan::findOrFail($id);
        if (Tenant::where('plan', $plan->code)->exists()) {
            $plan->update(['active' => false]);
            Audit::record('plan_retired', 'owner', 'plan:'.$plan->code);

            return response()->json(['retired' => true]);
        }
        $plan->delete();
        Audit::record('plan_deleted', 'owner', 'plan:'.$plan->code);

        return response()->json(['deleted' => true]);
    }
}
