<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use App\Domain\Organization\OrganizationRecord;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function show(Request $r)
    {
        return $r->user()->tenant;
    }

    public function save(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:150', 'settings' => 'required|array:timezone,work_week,hr_email,notifications,modules,brand_name',
            'settings.timezone' => 'required|timezone', 'settings.work_week' => 'required|array|min:1|max:7', 'settings.work_week.*' => 'integer|between:0,6|distinct',
            'settings.hr_email' => 'nullable|email|max:255', 'settings.notifications' => 'required|boolean', 'settings.modules' => 'present|array',
            'settings.modules.*' => [\Illuminate\Validation\Rule::in(\App\Domain\Access\WorkspaceProvisioner::MODULES), 'distinct'], 'settings.brand_name' => 'nullable|string|max:80']);

        return DB::transaction(function () use ($r, $data) {
            $tenant = Tenant::whereKey($r->user()->tenant_id)->lockForUpdate()->firstOrFail();
            // Settings owned by other modules (people configuration, setup extras) must survive this form's save.
            $data['settings'] = array_merge(array_diff_key($tenant->settings ?? [], array_flip(['timezone', 'work_week', 'hr_email', 'notifications', 'modules', 'brand_name'])), $data['settings']);
            $tenant->update($data);
            Audit::record('updated', 'settings', 'company:'.$tenant->id);

            return $tenant;
        });
    }

    private function stepRules(int $step): array
    {
        return match ($step) {
            1 => ['name' => 'required|string|max:150', 'timezone' => 'required|timezone'],
            2 => ['legal_entity' => 'required|string|max:150', 'registration_number' => 'nullable|string|max:80'],
            3 => ['location' => 'required|string|max:150', 'address' => 'required|string|max:500'],
            4 => ['departments' => 'required|array|min:1|max:50', 'departments.*' => 'required|string|max:100|distinct'],
            5 => ['work_week' => 'required|array|min:1|max:7', 'work_week.*' => 'integer|between:0,6|distinct'],
            6 => ['employee_types' => 'required|array|min:1|max:30', 'employee_types.*' => 'required|string|max:100|distinct'],
            7 => ['hr_email' => 'required|email|max:255', 'notifications' => 'required|boolean'],
            default => []
        };
    }

    public function setup(Request $r)
    {
        $r->validate(['step' => 'required|integer|between:1,8', 'data' => 'present|array', 'complete' => 'sometimes|boolean']);
        $step = (int) $r->step;
        $validated = validator($r->input('data'), $this->stepRules($step))->validate();

        return DB::transaction(function () use ($r, $step, $validated) {
            $t = Tenant::whereKey($r->user()->tenant_id)->lockForUpdate()->firstOrFail();
            abort_if($t->setup_completed_at, 422, 'Company setup is already complete. Use Settings to make changes.');
            $draft = $t->setup_draft ?? [];
            $draft[(string) $step] = $validated;
            if ($r->boolean('complete')) {
                abort_unless($step === 8, 422, 'Review all steps before completing setup.');
                for ($i = 1; $i <= 7; $i++) {
                    validator($draft[(string) $i] ?? [], $this->stepRules($i))->validate();
                }
                $t->name = $draft['1']['name'];
                $t->settings = array_merge($t->settings ?? [], ['timezone' => $draft['1']['timezone'], 'work_week' => $draft['5']['work_week'], 'hr_email' => $draft['7']['hr_email'], 'notifications' => $draft['7']['notifications']]);
                $groups = ['legal-entities' => [$draft['2']['legal_entity']], 'locations' => [$draft['3']['location']], 'departments' => $draft['4']['departments'], 'employee-types' => $draft['6']['employee_types']];
                foreach ($groups as $type => $names) {
                    foreach ($names as $index => $name) {
                        $model = OrganizationRecord::forType($type);
                        $code = 'SETUP-'.($index + 1);
                        $metadata = match ($type) {
                            'legal-entities' => ['registration_number' => $draft['2']['registration_number'] ?? null], 'locations' => ['address' => $draft['3']['address']], default => []
                        };
                        $model->newQuery()->updateOrCreate(['code' => $code], ['name' => $name, 'active' => true, 'metadata' => $metadata]);
                    }
                }
                $t->setup_completed_at = now();
            }
            $t->setup_draft = $draft;
            $t->setup_step = $step;
            $t->save();
            Audit::record($r->boolean('complete') ? 'setup_completed' : 'setup_saved', 'settings', 'company:'.$t->id, ['step' => $step]);

            return $t;
        });
    }
}
