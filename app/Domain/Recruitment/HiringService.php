<?php

namespace App\Domain\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Recruitment\Models\Application;
use App\Domain\Recruitment\Models\ApplicationAction;
use App\Domain\Recruitment\Models\Requisition;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class HiringService
{
    public const STAGES = ['applied', 'screening', 'assessment', 'interview', 'offer'];

    public function decide(User $actor, string $uuid, string $decision): Requisition
    {
        abort_unless($actor->allows('recruitment.approve'), 403);

        return DB::transaction(function () use ($actor, $uuid, $decision) {
            $record = app(HiringScope::class)->requisitions($actor, 'recruitment.approve')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_if($record->created_by === $actor->id, 403, 'A different authorized person must approve the requisition.');
            abort_unless($record->status === 'pending', 409, 'This requisition has already been reviewed.');
            $record->update(['status' => $decision, 'approved_by' => $actor->id, 'opened_at' => $decision === 'open' ? now() : null]);
            if ($decision === 'open') {
                app(PipelineService::class)->bind($record); // snapshot the pipeline version at open
            }
            if ($decision === 'open') {
                RecruitmentNotify::tell($record->recruiter_id, 'Requisition approved', 'A hiring requisition is open for sourcing.');
            }
            if ($decision === 'open') {
                app(TaskService::class)->createOnce(['title' => 'Source candidates for an open requisition', 'description' => 'The requisition was approved. Publish a posting and add candidates.', 'assignee_id' => $record->recruiter_id, 'source_module' => 'recruitment', 'source_entity' => 'sourcing:'.$record->uuid, 'action' => '/recruitment', 'status' => 'open', 'priority' => 'normal']);
            }
            app(TaskService::class)->completeBySource('recruitment', 'requisition:'.$record->uuid);
            Audit::record('requisition.'.$decision, 'recruitment', $record->uuid);

            return $record;
        });
    }

    public function apply(User $actor, array $data): Application
    {
        abort_unless($actor->allows('recruitment.manage'), 403);

        return DB::transaction(function () use ($actor, $data) {
            $requisition = app(HiringScope::class)->requisitions($actor, 'recruitment.manage')->where('uuid', $data['requisition_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless($requisition->status === 'open', 422, 'Applications require an approved, open requisition.');
            $candidate = app(HiringScope::class)->candidates($actor, 'recruitment.manage')->where('uuid', $data['candidate_uuid'])->firstOrFail();
            if ($candidate->retention_until < now('UTC')->toDateString()) {
                throw ValidationException::withMessages(['candidate_uuid' => 'Candidate retention period has expired; review privacy requirements before reuse.']);
            }
            $existing = Application::where('candidate_id', $candidate->id)->where('requisition_id', $requisition->id)->first();
            if ($existing) {
                return $existing;
            }
            $pipeline = app(PipelineService::class)->bind($requisition);
            $first = app(PipelineService::class)->firstStage($pipeline);
            $record = Application::create(['candidate_id' => $candidate->id, 'requisition_id' => $requisition->id, 'stage' => $first, 'status' => 'active', 'source' => $data['source'], 'stage_entered_at' => now()]);
            ApplicationAction::create(['application_id' => $record->id, 'actor_id' => $actor->id, 'from_stage' => null, 'to_stage' => $first, 'status' => 'active', 'reason' => 'Application recorded', 'idempotency_key' => (string) Str::uuid()]);
            Audit::record('application.created', 'recruitment', $record->uuid);

            return $record;
        });
    }

    public function transition(User $actor, string $uuid, array $data): Application
    {
        abort_unless($actor->allows('recruitment.manage'), 403);

        return DB::transaction(function () use ($actor, $uuid, $data) {
            $record = app(HiringScope::class)->applications($actor, 'recruitment.manage')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $retry = ApplicationAction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($retry) {
                abort_unless($retry->application_id === $record->id && $retry->actor_id === $actor->id && $retry->to_stage === $data['stage'] && $retry->status === $data['status'] && $retry->reason === $data['reason'], 409, 'Request key belongs to a different transition.');

                return $record;
            }
            abort_unless($record->status === 'active', 409, 'Closed applications cannot be moved.');
            abort_unless($record->stage === $data['expected_stage'], 409, 'Application changed; refresh before moving it.');
            $pipeline = app(PipelineService::class)->bind($record->requisition);
            $codes = $pipeline->stages->pluck('code')->all();
            $current = array_search($record->stage, $codes, true);
            $next = array_search($data['stage'], $codes, true);
            if ($data['status'] === 'active') {
                if ($next === false) {
                    throw ValidationException::withMessages(['stage' => 'This stage is not part of the requisition pipeline.']);
                }
                $kind = $pipeline->stages[$next]->kind;
                if (in_array($kind, ['hired', 'rejected'], true)) {
                    throw ValidationException::withMessages(['stage' => 'Hiring and rejection are recorded through the offer/handover workflow or by closing the application.']);
                }
                $ok = $current !== false && ($next === $current + 1 || ($pipeline->allow_skip && $next > $current));
                if (! $ok) {
                    throw ValidationException::withMessages(['stage' => $pipeline->allow_skip ? 'Move forward to a later stage of the pipeline.' : 'Move to the next stage. Hiring requires the separate offer and employee-conversion workflow.']);
                }
            }
            if ($data['status'] !== 'active' && $data['stage'] !== $record->stage) {
                throw ValidationException::withMessages(['stage' => 'Closing an application preserves its final stage.']);
            }
            ApplicationAction::create(['application_id' => $record->id, 'actor_id' => $actor->id, 'from_stage' => $record->stage, 'to_stage' => $data['stage'], 'status' => $data['status'], 'reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key']]);
            $record->update(['stage' => $data['stage'], 'status' => $data['status'], 'stage_entered_at' => now(), 'stage_reminded_at' => null, 'closed_at' => $data['status'] === 'active' ? null : now()]);
            Audit::record('application.transitioned', 'recruitment', $record->uuid, ['stage' => $record->stage, 'status' => $record->status]);

            return $record;
        });
    }
}
