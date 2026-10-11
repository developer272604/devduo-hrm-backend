<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\Models\AttendancePolicy;
use App\Domain\Attendance\Models\AttendancePolicyAssignment;
use App\Domain\Attendance\Models\AttendancePolicyVersion;
use App\Domain\Attendance\Policies\PolicyResolver;
use App\Domain\Attendance\Policies\PolicyService;
use App\Domain\Attendance\Processing\PolicySimulation;
use App\Domain\Attendance\Support\PolicyRules;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    public function __construct(private readonly PolicyService $service) {}

    private function present(AttendancePolicy $p, bool $versions = true): array
    {
        $out = ['uuid' => $p->uuid, 'name' => $p->name, 'code' => $p->code, 'description' => $p->description, 'country' => $p->country, 'active' => $p->active];
        if ($versions) {
            $out['versions'] = $p->versions->map(fn ($v) => $this->version($v))->all();
        }

        return $out;
    }

    private function version(AttendancePolicyVersion $v, bool $merged = false): array
    {
        return ['id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'effective_from' => $v->effective_from, 'effective_to' => $v->effective_to, 'rules' => $v->rules, 'rules_hash' => $v->rules_hash,
            'change_note' => $v->change_note, 'published_at' => $v->published_at] + ($merged ? ['effective_rules' => PolicyRules::merge($v->rules ?? [])] : []);
    }

    public function index()
    {
        return ['data' => AttendancePolicy::query()->with('versions')->orderBy('name')->get()->map(fn ($p) => $this->present($p))];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'code' => 'required|alpha_dash|max:40', 'description' => 'nullable|string', 'country' => 'nullable|string|size:2', 'rules' => 'nullable|array',
            'effective_from' => 'nullable|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d']);
        abort_if(AttendancePolicy::query()->where('code', $d['code'])->exists(), 422, 'A policy with this code already exists.');

        return response()->json(['data' => $this->present($this->service->create($d, $r->user())->load('versions'))], 201);
    }

    public function show(string $uuid)
    {
        $p = AttendancePolicy::byUuid($uuid)->load('versions');

        return ['data' => $this->present($p) + ['defaults' => PolicyRules::DEFAULTS]];
    }

    public function update(Request $r, string $uuid)
    {
        $d = $r->validate(['name' => 'sometimes|string|max:255', 'description' => 'nullable|string', 'country' => 'nullable|string|size:2', 'active' => 'sometimes|boolean']);

        return ['data' => $this->present($this->service->update(AttendancePolicy::byUuid($uuid), $d)->load('versions'))];
    }

    public function newVersion(Request $r, string $uuid)
    {
        $d = $r->validate(['change_note' => 'required|string', 'rules' => 'nullable|array', 'effective_from' => 'nullable|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d', 'base_version' => 'nullable|integer']);

        return response()->json(['data' => $this->version($this->service->newVersion(AttendancePolicy::byUuid($uuid), $d, $r->user()), true)], 201);
    }

    private function findVersion(string $uuid, int $n): AttendancePolicyVersion
    {
        $p = AttendancePolicy::byUuid($uuid);

        return AttendancePolicyVersion::query()->where('policy_id', $p->id)->where('version', $n)->firstOrFail()->setRelation('policy', $p);
    }

    public function showVersion(string $uuid, int $version)
    {
        return ['data' => $this->version($this->findVersion($uuid, $version), true)];
    }

    public function updateVersion(Request $r, string $uuid, int $version)
    {
        $d = $r->validate(['rules' => 'sometimes|array', 'effective_from' => 'sometimes|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d', 'change_note' => 'nullable|string']);

        return ['data' => $this->version($this->service->updateDraft($this->findVersion($uuid, $version), $d), true)];
    }

    public function publish(Request $r, string $uuid, int $version)
    {
        $res = $this->service->publish($this->findVersion($uuid, $version), $r->user());

        return ['data' => $this->version($res['version']), 'closed' => $res['closed'], 'warnings' => $res['warnings']];
    }

    public function retire(string $uuid, int $version)
    {
        $res = $this->service->retire($this->findVersion($uuid, $version));

        return ['data' => $this->version($res['version']), 'warnings' => $res['warnings']];
    }

    public function assignments(Request $r)
    {
        $rows = AttendancePolicyAssignment::query()->with('policy')->when($r->input('policy'), fn ($q, $u) => $q->whereIn('policy_id', AttendancePolicy::query()->where('uuid', $u)->pluck('id')))
            ->orderBy('target_type')->orderBy('effective_from')->get();

        return ['data' => $rows->map(fn ($a) => $this->assignment($a))];
    }

    private function assignment(AttendancePolicyAssignment $a): array
    {
        return ['id' => $a->id, 'policy' => $a->policy?->name, 'policy_uuid' => $a->policy?->uuid, 'target_type' => $a->target_type, 'target_id' => $a->target_id, 'target_label' => $this->service->targetLabel($a->target_type, $a->target_id),
            'effective_from' => $a->effective_from, 'effective_to' => $a->effective_to, 'priority' => $a->priority, 'is_override' => $a->is_override, 'note' => $a->note];
    }

    private function assignmentRules(): array
    {
        return ['policy' => 'required|string', 'target_type' => 'required|string', 'target_id' => 'nullable', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d',
            'priority' => 'nullable|integer', 'is_override' => 'nullable|boolean', 'note' => 'nullable|string'];
    }

    public function storeAssignment(Request $r)
    {
        $d = $r->validate($this->assignmentRules());
        $d['policy_id'] = AttendancePolicy::byUuid($d['policy'])->id;

        return response()->json(['data' => $this->assignment($this->service->createAssignment($d, $r->user())->load('policy'))], 201);
    }

    public function updateAssignment(Request $r, int $id)
    {
        $d = $r->validate(array_map(fn ($v) => 'sometimes|'.$v, array_diff_key($this->assignmentRules(), ['policy' => 1])));

        return ['data' => $this->assignment($this->service->updateAssignment(AttendancePolicyAssignment::query()->findOrFail($id), $d)->load('policy'))];
    }

    public function destroyAssignment(int $id)
    {
        $this->service->deleteAssignment(AttendancePolicyAssignment::query()->findOrFail($id));

        return response()->noContent();
    }

    public function resolve(Request $r, PolicyResolver $resolver)
    {
        $d = $r->validate(['employee' => 'required|string', 'date' => 'required|date_format:Y-m-d']);
        $e = Employee::query()->where('uuid', $d['employee'])->firstOrFail();
        $t = $resolver->resolveWithTrace($e->id, $d['date']);
        $m = $t['match'];

        return ['data' => ['employee' => $e->uuid, 'date' => $d['date'], 'policy' => $m ? ['uuid' => $m['policy']->uuid, 'name' => $m['policy']->name, 'version' => $m['version']->version, 'label' => $m['version']->label()] : null,
            'matched_by' => $m ? $m['assignment']->target_type : null, 'considered' => $t['considered'],
            'explanation' => $m ? 'Matched the '.str_replace('_', ' ', $m['assignment']->target_type).' assignment (priority '.$m['assignment']->priority.') for '.$m['policy']->name.' v'.$m['version']->version.'.' : 'No attendance policy applies to this employee on this date.']];
    }

    public function simulate(Request $r, PolicySimulation $sim)
    {
        $d = $r->validate(['rules' => 'nullable|array', 'version_id' => 'nullable|integer', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d', 'sample_size' => 'nullable|integer|min:1|max:500',
            'scope' => 'required|array', 'scope.employee_uuids' => 'nullable|array', 'scope.department_id' => 'nullable|integer', 'scope.location_id' => 'nullable|integer', 'scope.tenant' => 'nullable|boolean']);
        if (! empty($d['scope']['employee_uuids'])) {
            $d['scope']['employee_ids'] = Employee::query()->whereIn('uuid', $d['scope']['employee_uuids'])->pluck('id')->all();
        }

        return ['data' => $sim->simulate($d)];
    }
}
