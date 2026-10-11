<?php

namespace App\Http\Controllers\Leave\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeavePolicyVersion;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Policies\PolicyDiff;
use App\Domain\Leave\Policies\PolicyResolver;
use App\Domain\Leave\Policies\PolicyService;
use App\Domain\Leave\Policies\Targets;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    public function __construct(private readonly PolicyService $service) {}

    private function present(LeavePolicy $p, bool $versions = true): array
    {
        $out = ['uuid' => $p->uuid, 'name' => $p->name, 'code' => $p->code, 'description' => $p->description, 'country' => $p->country, 'active' => (bool) $p->active];
        if ($versions) {
            $out['versions'] = $p->versions()->orderBy('version')->get()->map(fn ($v) => $this->version($v))->all();
        }

        return $out;
    }

    private function version(LeavePolicyVersion $v, bool $detail = false): array
    {
        $out = ['uuid' => $v->uuid, 'version' => $v->version, 'status' => $v->status, 'effective_from' => $v->effective_from, 'effective_to' => $v->effective_to, 'checksum' => $v->checksum,
            'change_note' => $v->change_note, 'published_at' => $v->published_at?->toIso8601String(), 'immutable' => $v->status !== PolicyService::DRAFT];
        if ($detail) {
            $out['rules'] = $v->rules ?? [];
            $out['type_rules'] = $v->typeRules()->get()->map(function ($row) {
                $t = LeaveType::query()->find($row->leave_type_id);

                return ['type' => $t?->uuid, 'code' => $t?->code, 'name' => $t?->name, 'rules' => $row->rules];
            })->all();
            $out['integrity_ok'] = $this->service->integrityOk($v);
        }

        return $out;
    }

    public function index()
    {
        $policies = LeavePolicy::query()->orderBy('name')->get();
        $policies->each(fn ($p) => $this->service->syncStatuses($p->id));

        return ['data' => $policies->map(fn ($p) => $this->present($p))];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'code' => 'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/', 'description' => 'nullable|string', 'country' => 'nullable|string|size:2',
            'rules' => 'nullable|array', 'type_rules' => 'nullable|array', 'type_rules.*.type' => 'required|string', 'type_rules.*.rules' => 'nullable|array',
            'effective_from' => 'nullable|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d', 'change_note' => 'nullable|string']);
        abort_if(LeavePolicy::query()->where('code', $d['code'])->exists(), 422, 'A policy with this code already exists.');

        return response()->json(['data' => $this->present($this->service->create($d, $r->user()))], 201);
    }

    public function show(string $uuid)
    {
        $p = LeavePolicy::byUuid($uuid);
        $this->service->syncStatuses($p->id);

        return ['data' => $this->present($p)];
    }

    public function update(Request $r, string $uuid)
    {
        $d = $r->validate(['name' => 'sometimes|string|max:255', 'description' => 'nullable|string', 'country' => 'nullable|string|size:2', 'active' => 'sometimes|boolean']);

        return ['data' => $this->present($this->service->update(LeavePolicy::byUuid($uuid), $d))];
    }

    // ---- Versions -------------------------------------------------------------------------------

    private function findVersion(string $uuid, int $n): LeavePolicyVersion
    {
        $p = LeavePolicy::byUuid($uuid);

        return LeavePolicyVersion::query()->where('policy_id', $p->id)->where('version', $n)->firstOrFail()->setRelation('policy', $p);
    }

    public function newVersion(Request $r, string $uuid)
    {
        $d = $r->validate(['change_note' => 'required|string', 'rules' => 'nullable|array', 'type_rules' => 'nullable|array', 'type_rules.*.type' => 'required|string', 'type_rules.*.rules' => 'nullable|array',
            'effective_from' => 'nullable|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d', 'base_version' => 'nullable|integer']);
        $d = array_filter($d, fn ($v, $k) => $v !== null || in_array($k, ['effective_to']), ARRAY_FILTER_USE_BOTH);

        return response()->json(['data' => $this->version($this->service->newVersion(LeavePolicy::byUuid($uuid), $d, $r->user()), true)], 201);
    }

    public function showVersion(string $uuid, int $version)
    {
        return ['data' => $this->version($this->findVersion($uuid, $version), true)];
    }

    public function updateVersion(Request $r, string $uuid, int $version)
    {
        $d = $r->validate(['rules' => 'sometimes|array', 'type_rules' => 'sometimes|array', 'type_rules.*.type' => 'required|string', 'type_rules.*.rules' => 'nullable|array',
            'effective_from' => 'sometimes|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d', 'change_note' => 'nullable|string']);

        return ['data' => $this->version($this->service->updateDraft($this->findVersion($uuid, $version), $d), true)];
    }

    public function destroyVersion(string $uuid, int $version)
    {
        $this->service->deleteDraft($this->findVersion($uuid, $version));

        return response()->noContent();
    }

    public function putTypeRule(Request $r, string $uuid, int $version, string $type)
    {
        $d = $r->validate(['rules' => 'required|array']);
        $t = LeaveType::query()->where('uuid', $type)->orWhere('code', $type)->firstOrFail();
        $this->service->setTypeRule($this->findVersion($uuid, $version), $t, $d['rules']);

        return ['data' => $this->version($this->findVersion($uuid, $version), true)];
    }

    public function deleteTypeRule(string $uuid, int $version, string $type)
    {
        $t = LeaveType::query()->where('uuid', $type)->orWhere('code', $type)->firstOrFail();
        $this->service->removeTypeRule($this->findVersion($uuid, $version), $t);

        return response()->noContent();
    }

    public function publish(Request $r, string $uuid, int $version)
    {
        $res = $this->service->publish($this->findVersion($uuid, $version), $r->user());

        return ['data' => $this->version($res['version'], true), 'closed' => $res['closed'], 'warnings' => $res['warnings']];
    }

    public function schedule(Request $r, string $uuid, int $version)
    {
        $d = $r->validate(['effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d']);
        $v = $this->findVersion($uuid, $version);
        $this->service->updateDraft($v, $d);
        $res = $this->service->publish($v->refresh(), $r->user(), requireFuture: true);

        return ['data' => $this->version($res['version'], true), 'closed' => $res['closed'], 'warnings' => $res['warnings']];
    }

    public function retire(string $uuid, int $version)
    {
        $res = $this->service->retire($this->findVersion($uuid, $version));

        return ['data' => $this->version($res['version'], true), 'warnings' => $res['warnings']];
    }

    /** Policy Difference Viewer: ?from=<version no>&to=<version no>[&against=<other policy uuid>] (`to` then belongs to `against`). */
    public function diff(Request $r, string $uuid, PolicyDiff $diff)
    {
        $d = $r->validate(['from' => 'required|integer', 'to' => 'required|integer', 'against' => 'nullable|string']);

        return ['data' => $diff->compare($this->findVersion($uuid, (int) $d['from']), $this->findVersion($d['against'] ?? $uuid, (int) $d['to']))];
    }

    // ---- Assignments ----------------------------------------------------------------------------

    private function assignment(LeavePolicyAssignment $a): array
    {
        $p = LeavePolicy::query()->find($a->policy_id);

        return ['uuid' => $a->uuid, 'policy' => $p?->name, 'policy_uuid' => $p?->uuid, 'target_type' => $a->target_type, 'target_id' => $a->target_id, 'target_label' => Targets::label($a->target_type, $a->target_id),
            'effective_from' => $a->effective_from, 'effective_to' => $a->effective_to, 'priority' => (int) $a->priority, 'is_override' => (bool) $a->is_override, 'note' => $a->note];
    }

    public function assignments(Request $r)
    {
        $rows = LeavePolicyAssignment::query()->when($r->input('policy'), fn ($q, $u) => $q->whereIn('policy_id', LeavePolicy::query()->where('uuid', $u)->pluck('id')))
            ->orderBy('target_type')->orderBy('effective_from')->get();

        return ['data' => $rows->map(fn ($a) => $this->assignment($a))];
    }

    private function assignmentRules(): array
    {
        return ['policy' => 'required|string', 'target_type' => 'required|string', 'target_id' => 'nullable', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d',
            'priority' => 'nullable|integer', 'is_override' => 'nullable|boolean', 'note' => 'nullable|string'];
    }

    public function storeAssignment(Request $r)
    {
        $d = $r->validate($this->assignmentRules());
        $d['policy_id'] = LeavePolicy::byUuid($d['policy'])->id;

        return response()->json(['data' => $this->assignment($this->service->createAssignment($d, $r->user()))], 201);
    }

    public function updateAssignment(Request $r, string $uuid)
    {
        $d = $r->validate(array_map(fn ($v) => 'sometimes|'.$v, array_diff_key($this->assignmentRules(), ['policy' => 1])));

        return ['data' => $this->assignment($this->service->updateAssignment(LeavePolicyAssignment::byUuid($uuid), $d))];
    }

    public function destroyAssignment(string $uuid)
    {
        $this->service->deleteAssignment(LeavePolicyAssignment::byUuid($uuid));

        return response()->noContent();
    }

    // ---- Resolution -----------------------------------------------------------------------------

    /** Employee within the caller's data scope (HR policy managers by their role scope; anyone else by leave.view scope). */
    public static function scopedEmployee(Request $r, string $uuid): Employee
    {
        $employee = Employee::query()->where('uuid', $uuid)->firstOrFail();
        $scope = app(DataScope::class);
        abort_unless($scope->canAccess($r->user(), $employee, 'leave.policy.manage') || $scope->canAccess($r->user(), $employee, 'leave.manage') || $scope->canAccess($r->user(), $employee, 'leave.view'), 404);

        return $employee;
    }

    public function resolve(Request $r, PolicyResolver $resolver)
    {
        $d = $r->validate(['employee' => 'required|string', 'date' => 'required|date_format:Y-m-d', 'leave_type' => 'nullable|string']);
        $e = self::scopedEmployee($r, $d['employee']);
        $t = $resolver->resolveWithTrace($e->id, $d['date']);
        $m = $t['match'];
        $out = ['employee' => $e->uuid, 'date' => $d['date'], 'policy' => $m ? ['uuid' => $m['policy']->uuid, 'name' => $m['policy']->name, 'version' => $m['version']->version, 'version_uuid' => $m['version']->uuid] : null,
            'matched_by' => $m ? $m['assignment']->target_type : null, 'ambiguous' => $t['ambiguous'], 'considered' => $t['considered'],
            'explanation' => $m ? 'Matched the '.str_replace('_', ' ', $m['assignment']->target_type).' assignment (priority '.$m['assignment']->priority.') for '.$m['policy']->name.' v'.$m['version']->version.'.' : 'No leave policy applies to this employee on this date.'];
        if (! empty($d['leave_type'])) {
            $type = LeaveType::query()->where('uuid', $d['leave_type'])->orWhere('code', $d['leave_type'])->firstOrFail();
            $x = $resolver->explainType($e->id, $type->id, $d['date']);
            $out['leave_type'] = ['uuid' => $type->uuid, 'code' => $type->code, 'granted' => $x['result'] !== null, 'reason' => $x['result']['reason'] ?? $x['reason'],
                'rules' => $x['result']['rules'] ?? null, 'eligibility' => $x['result']['eligibility'] ?? null];
        } elseif ($m) {
            $out['types'] = $m['version']->typeRules()->get()->map(fn ($row) => LeaveType::query()->find($row->leave_type_id)?->code)->filter()->values()->all();
        }

        return ['data' => $out];
    }
}
