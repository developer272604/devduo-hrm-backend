<?php

namespace App\Domain\Lifecycle;

use App\Domain\Lifecycle\Models\{ExitInterview, LifecycleCase, LifecycleTask, Move};
use App\Domain\Tenancy\TenantClock;
use Illuminate\Support\Facades\DB;

/** Scheduled, idempotent, tenant-context jobs: reminders, escalation, due moves, exit-data retention. Re-running changes nothing already done. */
final class LifecycleJobs
{
    public function __construct(private CaseService $cases, private MoveService $moves) {}

    /** @return array{reminded:int,escalated:int} */
    public function remind(): array
    {
        $today = TenantClock::today();
        $soon = now()->parse($today)->addDays(365)->toDateString();
        $reminded = $escalated = 0;
        $open = fn () => LifecycleTask::where('status', 'pending')->whereNotNull('owner_user_id')->whereIn('case_id', LifecycleCase::where('status', 'open')->select('id'));
        foreach ($open()->whereNull('reminded_at')->whereBetween('due_date', [$today, $soon])->get() as $t) {
            if ($t->due_date->copy()->subDays((int) $t->remind_before_days)->toDateString() > $today) continue;
            if (LifecycleTask::whereKey($t->id)->whereNull('reminded_at')->update(['reminded_at' => now()])) {
                $this->cases->notify($t->owner_user_id, 'Lifecycle task due soon', $t->title.' is due '.$t->due_date->toDateString().'.', '/lifecycle/tasks/'.$t->uuid);
                $reminded++;
            }
        }
        foreach ($open()->whereNull('escalated_at')->where('due_date', '<', $today)->get() as $t) {
            if ($t->due_date->copy()->addDays((int) $t->escalate_after_days)->toDateString() > $today) continue;
            if (! LifecycleTask::whereKey($t->id)->whereNull('escalated_at')->update(['escalated_at' => now()])) continue;
            $tenantId = $t->tenant_id;
            foreach (array_slice(CaseService::usersWith($tenantId, 'lifecycle.manage'), 0, 5) as $hr) {
                $this->cases->notify($hr, 'Lifecycle task overdue', $t->title.' was due '.$t->due_date->toDateString().' and is still open.', '/lifecycle/tasks/'.$t->uuid);
            }
            DB::table('work_items')->where('tenant_id', $tenantId)->where('source_module', 'lifecycle')->where('source_entity', 'task:'.$t->uuid)->where('status', 'open')->update(['priority' => 'urgent']);
            $escalated++;
        }

        return compact('reminded', 'escalated');
    }

    /** @return array{moves_applied:int,exit_data_purged:int} */
    public function housekeeping(): array
    {
        $today = TenantClock::today();
        $applied = 0;
        foreach (Move::where('status', 'approved')->where('effective_date', '<=', $today)->get() as $m) {
            try {
                if ($this->moves->apply($m)->status === 'applied') $applied++;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        // Legal hold (Phase 15): a lifecycle/workspace-wide hold suspends exit-data purging.
        $purged = \App\Domain\Security\LegalHoldService::held('lifecycle') ? 0 : ExitInterview::where('status', 'submitted')->where('retention_until', '<', $today)->update(['answers' => null, 'status' => 'purged']);

        // HR can also finish or withdraw separation through People. Close the linked review tasks.
        foreach (\App\Domain\Lifecycle\Models\Resignation::whereIn('status', ['approved', 'withdrawal_pending'])->whereNotNull('separation_record_id')->get() as $request) {
            $status = \App\Domain\People\Models\SeparationRecord::whereKey($request->separation_record_id)->value('status');
            if (in_array($status, ['completed', 'withdrawn'], true)) {
                app(\App\Domain\Work\TaskService::class)->completeBySource('lifecycle', 'resignation:'.$request->uuid);
            }
        }

        return ['moves_applied' => $applied, 'exit_data_purged' => $purged];
    }
}
