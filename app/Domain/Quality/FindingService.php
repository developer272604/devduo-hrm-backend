<?php

namespace App\Domain\Quality;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\People\Insights\ViewerRelations;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reading and handling findings on behalf of a user. Visibility = data scope (people.quality.view) AND the finding's
 * classification evaluated by FieldAccess for the affected employee. Duplicate findings involve two people, so only
 * tenant-wide viewers see them. Findings outside the viewer's reach are 404.
 */
final class FindingService
{
    public const DUPLICATE_RULE = 'duplicate_employee';

    public function __construct(private DataScope $scope) {}

    public function visible(User $viewer, ?ViewerRelations $relations = null): Builder
    {
        $relations ??= ViewerRelations::for($viewer);
        $q = DataQualityFinding::query();
        if (! $this->scope->hasTenantWide($viewer, 'people.quality.view')) {
            $q->whereNotNull('employee_id')->where('rule', '!=', self::DUPLICATE_RULE);
            $this->scope->constrain($q, $viewer, 'people.quality.view', 'data_quality_findings.employee_id');
        }
        $relations->constrainStoredClass($q, 'data_quality_findings.classification', 'data_quality_findings.employee_id');

        return $q;
    }

    public function find(User $viewer, int $id): DataQualityFinding
    {
        return $this->visible($viewer)->whereKey($id)->firstOrFail();
    }

    /** @param iterable<DataQualityFinding> $findings @return array<int,array<string,mixed>> */
    public function present(iterable $findings): array
    {
        $findings = collect($findings);
        $employees = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', $findings->pluck('employee_id')->filter()->unique())
            ->get(['employees.id', 'employees.uuid', 'people.display_name'])->keyBy('id');
        $users = DB::table('users')->whereIn('id', $findings->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $rules = app(DataQualityEngine::class)->rules();

        return $findings->map(function (DataQualityFinding $f) use ($employees, $users, $rules) {
            $e = $employees[$f->employee_id] ?? null;
            $details = $f->details ?? [];
            unset($details['employee_ids']);

            return ['id' => $f->id, 'rule' => $f->rule, 'rule_label' => isset($rules[$f->rule]) ? $rules[$f->rule]->label() : $f->rule, 'category' => $f->category, 'severity' => $f->severity,
                'title' => $f->title, 'detail' => $f->detail, 'status' => $f->status, 'employee' => $e ? ['uuid' => $e->uuid, 'name' => $e->display_name] : null,
                'detected_at' => $f->detected_at?->toIso8601String(), 'last_seen_at' => $f->last_seen_at?->toIso8601String(), 'resolved_at' => $f->resolved_at?->toIso8601String(),
                'resolution' => $f->resolution, 'assignee' => $f->assignee_id ? ['id' => $f->assignee_id, 'name' => $users[$f->assignee_id] ?? null] : null, 'details' => $details,
                'supports_dismiss' => true, 'requires_resolution_text' => $f->rule === self::DUPLICATE_RULE];
        })->all();
    }

    public function acknowledge(DataQualityFinding $f, User $by): DataQualityFinding
    {
        abort_unless($f->status === 'open', 422, 'Only open findings can be acknowledged.');
        $f->forceFill(['status' => 'acknowledged', 'acknowledged_at' => now(), 'handled_by' => $by->id])->save();
        Audit::record('quality_acknowledged', 'people', 'finding:'.$f->id, ['rule' => $f->rule]);

        return $f;
    }

    public function resolve(DataQualityFinding $f, User $by, ?string $resolution): DataQualityFinding
    {
        abort_unless(in_array($f->status, DataQualityFinding::ACTIVE, true), 422, 'This finding is already closed.');
        $resolution = trim((string) $resolution);
        abort_if($f->rule === self::DUPLICATE_RULE && $resolution === '', 422, 'Describe how the duplicate was resolved.');

        return $this->close($f, $by, 'resolved', $resolution !== '' ? $resolution : 'Resolved manually', 'quality_resolved');
    }

    public function dismiss(DataQualityFinding $f, User $by, ?string $reason): DataQualityFinding
    {
        abort_unless(in_array($f->status, DataQualityFinding::ACTIVE, true), 422, 'This finding is already closed.');
        $reason = trim((string) $reason);
        $default = $f->rule === self::DUPLICATE_RULE ? 'Not a duplicate' : 'Dismissed';

        return $this->close($f, $by, 'dismissed', $reason !== '' ? $reason : $default, 'quality_dismissed');
    }

    public function reopen(DataQualityFinding $f, User $by): DataQualityFinding
    {
        abort_unless(in_array($f->status, ['resolved', 'dismissed'], true), 422, 'Only resolved or dismissed findings can be reopened.');
        $f->forceFill(['status' => 'open', 'resolved_at' => null, 'resolution' => null, 'acknowledged_at' => null, 'handled_by' => $by->id, 'last_seen_at' => now()])->save();
        Audit::record('quality_reopened', 'people', 'finding:'.$f->id, ['rule' => $f->rule]);

        return $f;
    }

    /** Assigns to an active user of the workspace and creates (once) a My Work task pointing at the affected record. */
    public function assign(DataQualityFinding $f, User $by, int $assigneeId): DataQualityFinding
    {
        abort_unless(in_array($f->status, DataQualityFinding::ACTIVE, true), 422, 'Reopen the finding before assigning it.');
        $assignee = User::query()->where('tenant_id', $f->tenant_id)->where('active', true)->whereKey($assigneeId)->first();
        abort_unless($assignee, 422, 'Choose an active user in this workspace.');

        return DB::transaction(function () use ($f, $by, $assignee) {
            $tasks = app(TaskService::class);
            $employee = $f->employee_id ? DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $f->employee_id)->first(['employees.uuid', 'people.display_name']) : null;
            if ($f->assignee_id && $f->assignee_id !== $assignee->id) {
                $tasks->completeBySource('people', 'quality:'.$f->id);
            }
            $f->forceFill(['assignee_id' => $assignee->id, 'handled_by' => $by->id])->save();
            $tasks->createOnce([
                'assignee_id' => $assignee->id, 'title' => 'Resolve data quality issue: '.$f->title.($employee ? ' ('.$employee->display_name.')' : ''), 'description' => $f->detail,
                'source_module' => 'people', 'source_entity' => 'quality:'.$f->id, 'priority' => in_array($f->severity, ['critical', 'action_required'], true) ? 'high' : 'normal',
                'due_date' => now()->addDays($f->severity === 'critical' ? 2 : 7)->toDateString(), 'action' => $employee ? '/employees/'.$employee->uuid : '/people/quality',
                'metadata' => ['finding_id' => $f->id, 'rule' => $f->rule, 'employee_uuid' => $employee?->uuid],
            ]);
            Audit::record('quality_assigned', 'people', 'finding:'.$f->id, ['assignee_id' => $assignee->id]);

            return $f;
        });
    }

    private function close(DataQualityFinding $f, User $by, string $status, string $text, string $audit): DataQualityFinding
    {
        $f->forceFill(['status' => $status, 'resolved_at' => now(), 'resolution' => mb_substr($text, 0, 1000), 'handled_by' => $by->id])->save();
        app(TaskService::class)->completeBySource('people', 'quality:'.$f->id);
        Audit::record($audit, 'people', 'finding:'.$f->id, ['rule' => $f->rule]);

        return $f;
    }
}
