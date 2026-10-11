<?php

namespace App\Http\Controllers\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Organization\OrganizationRecord;
use App\Domain\Recruitment\HiringScope;
use App\Domain\Recruitment\HiringService;
use App\Domain\Recruitment\Models\ApplicationAction;
use App\Domain\Recruitment\Models\Candidate;
use App\Domain\Recruitment\Models\JobDefinition;
use App\Domain\Recruitment\Models\Pipeline;
use App\Domain\Recruitment\Models\Requisition;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Support\RequestFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecruitmentController
{
    public function jobs()
    {
        return JobDefinition::latest('id')->paginate(25)->through(fn ($j) => $j->only(['uuid', 'code', 'title', 'description', 'version', 'default_pipeline_code']));
    }

    public function createJob(Request $r)
    {
        $data = $r->validate(['code' => 'required|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'title' => 'required|string|max:160', 'description' => 'required|string|max:20000', 'default_pipeline_code' => 'nullable|regex:/^[A-Z][A-Z0-9_]{0,39}$/']);
        $record = DB::transaction(function () use ($r, $data) {
            DB::table('tenants')->where('id', $r->user()->tenant_id)->lockForUpdate()->first();
            if (! empty($data['default_pipeline_code'])) {
                Pipeline::where('code', $data['default_pipeline_code'])->where('status', 'active')->firstOrFail();
            }
            $record = JobDefinition::create($data + ['version' => (JobDefinition::where('code', $data['code'])->max('version') ?? 0) + 1]);
            Audit::record('job.versioned', 'recruitment', $record->uuid);

            return $record;
        });

        return response()->json(['uuid' => $record->uuid], 201);
    }

    public function users(Request $r)
    {
        return User::where('tenant_id', $r->user()->tenant_id)->where('active', true)->whereHas('roles.permissions', fn ($q) => $q->whereIn('key', ['recruitment.view', 'recruitment.manage', 'recruitment.approve']))->orderBy('name')->paginate(25)->through(fn ($u) => ['id' => $u->id, 'name' => $u->name]);
    }

    public function requisitions(Request $r, HiringScope $scope)
    {
        return $scope->requisitions($r->user())->with(['job', 'pipeline.stages'])->latest('id')->paginate(25)->through(fn ($q) => [
            'uuid' => $q->uuid, 'title' => $q->job->title, 'job_version' => $q->job->version, 'openings' => $q->openings,
            'status' => $q->status, 'confidential' => $q->confidential, 'target_date' => $q->target_date->toDateString(), 'filled_count' => $q->filled_count,
            'budget_amount' => $q->budget_amount, 'budget_currency' => $q->budget_currency, 'department_id' => $q->department_id, 'position_id' => $q->position_id,
            'version' => (int) $q->version, 'pipeline_code' => $q->pipeline_code, 'can_review' => $q->created_by !== $r->user()->id && $q->status === 'pending',
            'pipeline_uuid' => $q->pipeline?->uuid, 'pipeline_version' => $q->pipeline ? (int) $q->pipeline->version : null,
            'pipeline_stages' => $q->pipeline?->stages->map(fn ($stage) => $stage->only(['code', 'label', 'kind', 'sla_hours']))->values()->all(),
        ]);
    }

    public function createRequisition(Request $r)
    {
        $data = $r->validate(['job_uuid' => 'required|uuid', 'legal_entity_id' => 'required|integer', 'position_id' => 'nullable|integer', 'recruiter_id' => 'required|integer', 'hiring_manager_id' => 'required|integer', 'openings' => 'required|integer|min:1|max:10000', 'target_date' => 'required|date_format:Y-m-d', 'kind' => ['required', Rule::in(['new', 'replacement', 'contract', 'intern'])], 'confidential' => 'boolean', 'pipeline_code' => 'nullable|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'department_id' => 'nullable|integer', 'location_id' => 'nullable|integer', 'budget_amount' => 'nullable|numeric|min:0|max:999999999999', 'budget_currency' => 'nullable|required_with:budget_amount|alpha|size:3', 'reason' => 'required|string|max:2000', 'idempotency_key' => 'required|uuid']);
        $job = JobDefinition::where('uuid', $data['job_uuid'])->firstOrFail();
        if (empty($data['pipeline_code']) && $job->default_pipeline_code) {
            $data['pipeline_code'] = $job->default_pipeline_code;
        }
        OrganizationRecord::forType('legal-entities')->newQuery()->whereKey($data['legal_entity_id'])->firstOrFail();
        if (! empty($data['department_id'])) {
            OrganizationRecord::forType('departments')->newQuery()->whereKey($data['department_id'])->firstOrFail();
        }
        if (! empty($data['location_id'])) {
            OrganizationRecord::forType('locations')->newQuery()->whereKey($data['location_id'])->firstOrFail();
        }
        if (isset($data['budget_currency'])) {
            $data['budget_currency'] = strtoupper($data['budget_currency']);
        }
        if (isset($data['budget_amount'])) {
            $data['budget_amount'] = number_format((float) $data['budget_amount'], 2, '.', '');
        }
        if (! empty($data['position_id'])) {
            $position = OrganizationRecord::forType('positions')->newQuery()->whereKey($data['position_id'])->firstOrFail();
            $cap = $position->metadata['headcount'] ?? null;
            if ($cap !== null) {
                $committed = (int) Requisition::where('position_id', $position->id)->whereIn('status', ['pending', 'open'])->sum('openings') + (int) Requisition::where('position_id', $position->id)->where('status', 'filled')->sum('openings');
                if ($committed + $data['openings'] > (int) $cap) {
                    throw ValidationException::withMessages(['openings' => 'Approved headcount for this position is '.(int) $cap.'; '.$committed.' is already committed to requisitions.']);
                }
            }
        }
        foreach (['recruiter_id', 'hiring_manager_id'] as $key) {
            $u = User::where('tenant_id', $r->user()->tenant_id)->where('active', true)->whereKey($data[$key])->firstOrFail();
            if (! $u->allows('recruitment.view')) {
                throw ValidationException::withMessages([$key => 'Choose a user with recruitment viewing permission.']);
            }
        }
        if (! empty($data['pipeline_code'])) {
            Pipeline::where('code', $data['pipeline_code'])->where('status', 'active')->firstOrFail();
        }
        unset($data['job_uuid']);
        $record = DB::transaction(function () use ($r, $data, $job) {
            DB::table('tenants')->where('id', $r->user()->tenant_id)->lockForUpdate()->first();
            $existing = Requisition::where('idempotency_key', $data['idempotency_key'])->first();
            $fingerprint = RequestFingerprint::make($data + ['job_uuid' => $job->uuid]);
            if ($existing) {
                abort_unless($existing->created_by === $r->user()->id && hash_equals($existing->request_hash, $fingerprint), 409, 'Request key belongs to a different requisition.');

                return $existing;
            }
            $record = Requisition::create($data + ['job_id' => $job->id, 'created_by' => $r->user()->id, 'request_hash' => $fingerprint]);
            $approvers = User::where('tenant_id', $r->user()->tenant_id)->where('active', true)->where('id', '!=', $r->user()->id)->whereHas('roles.permissions', fn ($q) => $q->where('key', 'recruitment.approve'))->get();
            foreach ($approvers as $approver) {
                if (app(HiringScope::class)->requisitions($approver, 'recruitment.approve')->whereKey($record->id)->exists()) {
                    app(TaskService::class)->createOnce([
                        'title' => 'Review hiring requisition', 'description' => 'Review the requested role and headcount in Recruitment.', 'assignee_id' => $approver->id,
                        'source_module' => 'recruitment', 'source_entity' => 'requisition:'.$record->uuid, 'action' => '/recruitment', 'status' => 'open', 'priority' => 'normal', 'metadata' => ['approval_request_id' => $record->uuid],
                    ]);
                }
            }
            Audit::record('requisition.requested', 'recruitment', $record->uuid);

            return $record;
        });

        return response()->json(['uuid' => $record->uuid], 201);
    }

    public function decide(Request $r, string $uuid, HiringService $service)
    {
        $data = $r->validate(['decision' => ['required', Rule::in(['open', 'rejected'])]]);

        return ['uuid' => $service->decide($r->user(), $uuid, $data['decision'])->uuid];
    }

    public function candidates(Request $r, HiringScope $scope)
    {
        Audit::record('candidates.viewed', 'recruitment', 'candidate-list');

        return $scope->candidates($r->user())->where('retention_until', '>=', now('UTC')->toDateString())->latest('id')->paginate(25)->through(fn ($c) => ['uuid' => $c->uuid, 'name' => $c->name, 'email' => $r->user()->allows('recruitment.manage') ? $c->email : self::mask($c->email), 'source' => $c->source, 'privacy_notice_version' => $c->privacy_notice_version, 'retention_until' => $c->retention_until]);
    }

    public static function mask(?string $email): string
    {
        [$local, $domain] = array_pad(explode('@', (string) $email, 2), 2, '');

        return substr($local, 0, 1).str_repeat('*', max(2, strlen($local) - 1)).'@'.$domain;
    }

    public function createCandidate(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:160', 'email' => 'required|email:rfc|max:254', 'phone' => 'nullable|string|max:40', 'source' => 'required|string|max:80', 'privacy_notice_version' => 'required|string|max:80', 'consent_reference' => 'required|string|max:2000', 'consented_at' => 'required|date|before_or_equal:now', 'retention_until' => 'required|date_format:Y-m-d|after:today|before_or_equal:'.now('UTC')->addYears(2)->toDateString()]);
        $data['email'] = strtolower(trim($data['email']));
        $data['email_hash'] = hash_hmac('sha256', $data['email'], config('app.key'));
        $data['consented_at'] = CarbonImmutable::parse($data['consented_at'])->utc()->format('Y-m-d H:i:s');
        $record = DB::transaction(function () use ($r, $data) {
            DB::table('tenants')->where('id', $r->user()->tenant_id)->lockForUpdate()->first();
            if (Candidate::where('email_hash', $data['email_hash'])->exists()) {
                abort(409, 'A matching candidate exists. An authorized recruiter must review the duplicate; no automatic merge was performed.');
            }
            $c = Candidate::create($data + ['created_by' => $r->user()->id]);
            Audit::record('candidate.created', 'recruitment', $c->uuid);

            return $c;
        });
        $similar = app(HiringScope::class)->candidates($r->user(), 'recruitment.manage')->where('name', $record->name)->where('id', '!=', $record->id)->pluck('uuid');

        return response()->json(['uuid' => $record->uuid, 'possible_duplicates' => $similar], 201);
    }

    public function applications(Request $r, HiringScope $scope)
    {
        return $scope->applications($r->user())->whereHas('candidate', fn ($q) => $q->where('retention_until', '>=', now('UTC')->toDateString()))->with(['candidate', 'requisition.job'])->latest('id')->paginate(25)->through(fn ($a) => [
            'uuid' => $a->uuid, 'candidate_uuid' => $a->candidate->uuid, 'candidate_name' => $a->candidate->name,
            'requisition_uuid' => $a->requisition->uuid, 'job_title' => $a->requisition->job->title,
            'stage' => $a->stage, 'status' => $a->status, 'source' => $a->source, 'stage_entered_at' => $a->stage_entered_at,
        ]);
    }

    public function apply(Request $r, HiringService $service)
    {
        $data = $r->validate(['candidate_uuid' => 'required|uuid', 'requisition_uuid' => 'required|uuid', 'source' => 'required|string|max:80']);

        return response()->json(['uuid' => $service->apply($r->user(), $data)->uuid], 201);
    }

    public function transition(Request $r, string $uuid, HiringService $service)
    {
        $data = $r->validate(['stage' => ['required', 'regex:/^[a-z][a-z0-9_]{0,23}$/'], 'expected_stage' => ['required', 'regex:/^[a-z][a-z0-9_]{0,23}$/'], 'status' => ['required', Rule::in(['active', 'rejected', 'withdrawn'])], 'reason' => 'required|string|max:2000', 'idempotency_key' => 'required|uuid']);

        return ['uuid' => $service->transition($r->user(), $uuid, $data)->uuid];
    }

    public function history(Request $r, string $uuid, HiringScope $scope)
    {
        $record = $scope->applications($r->user())->where('uuid', $uuid)->firstOrFail();

        return ApplicationAction::where('application_id', $record->id)->orderBy('id')->paginate(25)->through(fn ($a) => $a->only(['uuid', 'from_stage', 'to_stage', 'status', 'reason', 'created_at']));
    }
}
