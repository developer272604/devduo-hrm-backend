<?php

namespace App\Http\Controllers\Recruitment;

use App\Domain\Recruitment\HiringScope;
use App\Domain\Recruitment\Models\Pipeline;
use App\Domain\Recruitment\Models\RequisitionVersion;
use App\Domain\Recruitment\PipelineService;
use App\Domain\Recruitment\RequisitionVersionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PipelineController
{
    private function rules(bool $withCode): array
    {
        return ($withCode ? ['code' => 'required|regex:/^[A-Z][A-Z0-9_]{0,39}$/'] : []) + [
            'name' => 'required|string|max:120', 'allow_skip' => 'boolean', 'feedback_reminder_hours' => 'nullable|integer|min:1|max:8760',
            'stages' => 'required|array|min:2|max:20',
            'stages.*.code' => ['required', 'regex:/^[a-z][a-z0-9_]{0,23}$/'], 'stages.*.label' => 'required|string|max:80',
            'stages.*.kind' => ['required', Rule::in(PipelineService::KINDS)], 'stages.*.sla_hours' => 'nullable|integer|min:1|max:8760',
            'stages.*.reminder_recipients' => 'nullable|array|max:2', 'stages.*.reminder_recipients.*' => [Rule::in(PipelineService::RECIPIENTS)],
        ];
    }

    public function index(PipelineService $service)
    {
        $service->ensureDefault(); // every tenant has a default pipeline equal to the former fixed stages

        return Pipeline::with('stages')->orderBy('code')->orderByDesc('version')->paginate(50)->through(fn ($p) => PipelineService::present($p));
    }

    public function show(string $uuid)
    {
        return PipelineService::present(Pipeline::where('uuid', $uuid)->firstOrFail());
    }

    public function versions(string $uuid)
    {
        $code = Pipeline::where('uuid', $uuid)->firstOrFail()->code;

        return Pipeline::with('stages')->where('code', $code)->orderByDesc('version')->get()->map(fn ($p) => PipelineService::present($p))->all();
    }

    public function store(Request $r, PipelineService $service)
    {
        return response()->json(PipelineService::present($service->create($r->user(), $r->validate($this->rules(true)))), 201);
    }

    public function update(Request $r, string $uuid, PipelineService $service)
    {
        return PipelineService::present($service->update($r->user(), $uuid, $r->validate($this->rules(false))));
    }

    public function newVersion(Request $r, string $uuid, PipelineService $service)
    {
        return response()->json(PipelineService::present($service->newVersion($r->user(), $uuid, $r->validate($this->rules(false)))), 201);
    }

    public function makeDefault(Request $r, string $uuid, PipelineService $service)
    {
        return PipelineService::present($service->makeDefault($r->user(), $uuid));
    }

    public function destroy(Request $r, string $uuid, PipelineService $service)
    {
        $service->delete($r->user(), $uuid);

        return response()->noContent();
    }

    public function amend(Request $r, string $uuid, RequisitionVersionService $service)
    {
        $data = $r->validate(['openings' => 'sometimes|integer|min:1|max:10000', 'budget_amount' => 'sometimes|nullable|numeric|min:0|max:999999999999', 'budget_currency' => 'sometimes|nullable|alpha|size:3',
            'department_id' => 'sometimes|nullable|integer', 'location_id' => 'sometimes|nullable|integer', 'job_uuid' => 'sometimes|uuid', 'target_date' => 'sometimes|date_format:Y-m-d',
            'reason' => 'required|string|max:2000', 'expected_version' => 'nullable|integer|min:1']);
        $v = $service->amend($r->user(), $uuid, collect($data)->except(['reason', 'expected_version'])->all(), $data['reason'], $data['expected_version'] ?? null);

        return response()->json(RequisitionVersionService::present($v), 201);
    }

    public function requisitionVersions(Request $r, string $uuid, HiringScope $scope)
    {
        $req = $scope->requisitions($r->user())->where('uuid', $uuid)->firstOrFail();

        return RequisitionVersion::where('requisition_id', $req->id)->orderByDesc('version')->paginate(25)->through(fn ($v) => RequisitionVersionService::present($v));
    }

    public function decideVersion(Request $r, string $uuid, int $version, RequisitionVersionService $service)
    {
        $data = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);

        return RequisitionVersionService::present($service->decide($r->user(), $uuid, $version, $data['decision']));
    }
}
