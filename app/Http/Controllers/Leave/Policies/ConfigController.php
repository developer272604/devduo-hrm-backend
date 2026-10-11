<?php

namespace App\Http\Controllers\Leave\Policies;

use App\Domain\Leave\Eligibility\EligibilityEvaluator;
use App\Domain\Leave\Models\LeaveDocumentRule;
use App\Domain\Leave\Models\LeaveOptionalHolidayPool;
use App\Domain\Leave\Models\LeavePolicyVersion;
use App\Domain\Leave\Models\LeaveRestriction;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Policies\PolicyResolver;
use App\Domain\Leave\Policies\RuleConfigService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Restriction rules, document rules, optional/floating holiday pools and the eligibility check. */
class ConfigController extends Controller
{
    public function __construct(private readonly RuleConfigService $service) {}

    private static function typeUuid(?int $id): ?string
    {
        return $id ? LeaveType::query()->whereKey($id)->value('uuid') : null;
    }

    private function restriction(LeaveRestriction $r): array
    {
        return ['uuid' => $r->uuid, 'name' => $r->name, 'kind' => $r->kind, 'action' => $r->action, 'exempt_statutory' => (bool) $r->exempt_statutory, 'target_type' => $r->target_type, 'target_id' => $r->target_id,
            'leave_type' => self::typeUuid($r->leave_type_id), 'start_date' => $r->start_date, 'end_date' => $r->end_date, 'params' => $r->params, 'active' => (bool) $r->active];
    }

    public function restrictions(Request $r)
    {
        $rows = LeaveRestriction::query()->when($r->input('kind'), fn ($q, $k) => $q->where('kind', $k))->orderBy('kind')->orderBy('name')->get();

        return ['data' => $rows->map(fn ($x) => $this->restriction($x))];
    }

    private function restrictionRules(bool $create): array
    {
        $s = $create ? 'required' : 'sometimes';

        return ['name' => "$s|string|max:255", 'kind' => "$s|string", 'action' => 'sometimes|string', 'exempt_statutory' => 'sometimes|boolean', 'target_type' => 'sometimes|string', 'target_id' => 'nullable',
            'leave_type' => 'nullable|string', 'start_date' => 'nullable|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d', 'params' => 'nullable|array', 'active' => 'sometimes|boolean'];
    }

    public function storeRestriction(Request $r)
    {
        return response()->json(['data' => $this->restriction($this->service->createRestriction($r->validate($this->restrictionRules(true)), $r->user()))], 201);
    }

    public function updateRestriction(Request $r, string $uuid)
    {
        return ['data' => $this->restriction($this->service->updateRestriction(LeaveRestriction::byUuid($uuid), $r->validate($this->restrictionRules(false))))];
    }

    public function destroyRestriction(string $uuid)
    {
        $this->service->deleteRestriction(LeaveRestriction::byUuid($uuid));

        return response()->noContent();
    }

    // ---- Document rules ---------------------------------------------------------------------------

    private function document(LeaveDocumentRule $d): array
    {
        return ['id' => $d->id, 'leave_type' => self::typeUuid($d->leave_type_id), 'policy_version' => $d->policy_version_id ? LeavePolicyVersion::query()->whereKey($d->policy_version_id)->value('uuid') : null,
            'required_after_units' => \App\Domain\Leave\Support\Units::normalize($d->required_after_units), 'document_kind' => $d->document_kind, 'mandatory' => (bool) $d->mandatory, 'is_sensitive' => (bool) $d->is_sensitive,
            'submit_within_days' => $d->submit_within_days, 'retention_days' => $d->retention_days, 'description' => $d->description, 'active' => (bool) $d->active];
    }

    private function documentRules(bool $create): array
    {
        $s = $create ? 'required' : 'sometimes';

        return ['leave_type' => "$s|string", 'policy_version' => 'nullable|string', 'required_after_units' => 'sometimes|numeric|min:0', 'document_kind' => 'sometimes|string|max:40', 'mandatory' => 'sometimes|boolean',
            'is_sensitive' => 'sometimes|boolean', 'submit_within_days' => 'nullable|integer|min:0', 'retention_days' => 'nullable|integer|min:0', 'description' => 'nullable|string', 'active' => 'sometimes|boolean'];
    }

    public function documentRulesIndex(Request $r)
    {
        $rows = LeaveDocumentRule::query()->when($r->input('leave_type'), fn ($q, $u) => $q->whereIn('leave_type_id', LeaveType::query()->where('uuid', $u)->pluck('id')))->orderBy('leave_type_id')->orderBy('id')->get();

        return ['data' => $rows->map(fn ($x) => $this->document($x))];
    }

    public function storeDocumentRule(Request $r)
    {
        $d = $r->validate($this->documentRules(true));
        if (isset($d['required_after_units'])) {
            $d['required_after_units'] = (string) $r->input('required_after_units');
        }

        return response()->json(['data' => $this->document($this->service->saveDocumentRule(null, $d))], 201);
    }

    public function updateDocumentRule(Request $r, int $id)
    {
        $d = $r->validate($this->documentRules(false));
        if (isset($d['required_after_units'])) {
            $d['required_after_units'] = (string) $r->input('required_after_units');
        }

        return ['data' => $this->document($this->service->saveDocumentRule(LeaveDocumentRule::query()->findOrFail($id), $d))];
    }

    public function destroyDocumentRule(int $id)
    {
        $this->service->deleteDocumentRule(LeaveDocumentRule::query()->findOrFail($id));

        return response()->noContent();
    }

    // ---- Optional / floating holiday pools --------------------------------------------------------

    private function pool(LeaveOptionalHolidayPool $p): array
    {
        return ['uuid' => $p->uuid, 'name' => $p->name, 'year' => (int) $p->year, 'max_selection' => (int) $p->max_selection, 'leave_type' => self::typeUuid($p->leave_type_id), 'holidays' => $p->holidays,
            'applicability' => $p->applicability, 'active' => (bool) $p->active];
    }

    private function poolRules(bool $create): array
    {
        $s = $create ? 'required' : 'sometimes';

        return ['name' => "$s|string|max:255", 'year' => "$s|integer", 'max_selection' => 'sometimes|integer|min:1', 'leave_type' => 'nullable|string', 'holidays' => "$s|array", 'holidays.*.date' => 'required|string',
            'holidays.*.name' => 'nullable|string|max:255', 'holidays.*.holiday_uuid' => 'nullable|string', 'applicability' => 'nullable|array', 'active' => 'sometimes|boolean'];
    }

    public function pools(Request $r)
    {
        $rows = LeaveOptionalHolidayPool::query()->when($r->input('year'), fn ($q, $y) => $q->where('year', (int) $y))->orderByDesc('year')->orderBy('name')->get();

        return ['data' => $rows->map(fn ($x) => $this->pool($x))];
    }

    public function storePool(Request $r)
    {
        return response()->json(['data' => $this->pool($this->service->savePool(null, $r->validate($this->poolRules(true))))], 201);
    }

    public function updatePool(Request $r, string $uuid)
    {
        return ['data' => $this->pool($this->service->savePool(LeaveOptionalHolidayPool::byUuid($uuid), $r->validate($this->poolRules(false))))];
    }

    public function destroyPool(string $uuid)
    {
        $this->service->deletePool(LeaveOptionalHolidayPool::byUuid($uuid));

        return response()->noContent();
    }

    // ---- Eligibility ------------------------------------------------------------------------------

    /** GET eligibility/check?employee=&leave_type=&date= : applicability + probation/tenure/notice checks with reasons. */
    public function eligibility(Request $r, PolicyResolver $resolver, EligibilityEvaluator $evaluator)
    {
        $d = $r->validate(['employee' => 'required|string', 'leave_type' => 'required|string', 'date' => 'required|date_format:Y-m-d']);
        $e = PolicyController::scopedEmployee($r, $d['employee']);
        $type = LeaveType::query()->where('uuid', $d['leave_type'])->orWhere('code', $d['leave_type'])->firstOrFail();
        $x = $resolver->explainType($e->id, $type->id, $d['date']);

        return ['data' => ['employee' => $e->uuid, 'leave_type' => $type->code, 'date' => $d['date'], 'granted' => $x['result'] !== null, 'reason' => $x['result']['reason'] ?? $x['reason'],
            'eligibility' => $x['result']['eligibility'] ?? null]];
    }
}
