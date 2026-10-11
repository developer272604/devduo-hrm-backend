<?php

namespace App\Domain\Leave\Requests;

use App\Domain\Access\DataScope;
use App\Domain\Leave\Models\LeaveConflict;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestDocument;
use App\Domain\Leave\Models\LeaveRequestSegment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * API shapes for leave requests. Only public UUIDs leave the API; user ids become names.
 *
 * MEDICAL PRIVACY: for a sensitive leave type the type name, reason, documents and the "why" of the history are shown only to
 * the employee, to users holding leave.medical.view within their data scope, and (when the tenant opts in through
 * privacy.managers_see_medical / managers_see_reason) to the employee's managers. Everyone else sees a neutral "Leave".
 */
final class RequestPresenter
{
    private array $names = [];

    private array $types = [];

    private array $employees = [];

    public function __construct(private LeaveApprovers $approvers, private DataScope $scope) {}

    public function employee(?Employee $e): ?array
    {
        if (! $e) {
            return null;
        }
        $e->loadMissing('person');

        return ['uuid' => $e->uuid, 'name' => $e->person?->display_name, 'employee_number' => $e->employee_number];
    }

    public function userName(?int $id): ?string
    {
        return $id ? ($this->names[$id] ??= User::query()->whereKey($id)->value('name')) : null;
    }

    /** True when $viewer may see sensitive (medical) detail of this employee's request. */
    public function canSeeSensitive(User $viewer, Employee $employee): bool
    {
        if ($this->scope->isSelf($viewer, $employee)) {
            return true;
        }
        if ($viewer->allows('leave.medical.view') && $this->scope->canAccess($viewer, $employee, 'leave.medical.view')) {
            return true;
        }

        return (bool) LeaveSettings::get('privacy.managers_see_medical', false) && $this->scope->isManagerOf($viewer, $employee);
    }

    public function canSeeReason(User $viewer, Employee $employee, bool $sensitive): bool
    {
        if (! $sensitive || $this->canSeeSensitive($viewer, $employee)) {
            return true;
        }

        return (bool) LeaveSettings::get('privacy.managers_see_reason', false) && $this->scope->isManagerOf($viewer, $employee);
    }

    public function type(int $id, bool $reveal): array
    {
        $t = $this->types[$id] ??= LeaveType::query()->findOrFail($id);

        return $t->is_sensitive && ! $reveal ? ['uuid' => null, 'name' => 'Leave', 'code' => null, 'color' => null, 'sensitive' => true]
            : ['uuid' => $t->uuid, 'name' => $t->name, 'code' => $t->code, 'color' => $t->color, 'kind' => $t->kind, 'category' => $t->category, 'unit' => $t->unit, 'sensitive' => (bool) $t->is_sensitive];
    }

    public function summary(LeaveRequest $r, User $viewer): array
    {
        $employee = $this->employees[$r->employee_id] ??= Employee::query()->with('person')->findOrFail($r->employee_id);
        $self = $this->scope->isSelf($viewer, $employee);
        $reveal = $this->canSeeSensitive($viewer, $employee);
        $pending = $r->status === 'pending';
        $hr = $viewer->allows('leave.manage') && $this->scope->canAccess($viewer, $employee, 'leave.manage');
        $delegate = $r->delegate_employee_id ? Employee::query()->with('person')->find($r->delegate_employee_id) : null;

        return [
            'uuid' => $r->uuid, 'employee' => $this->employee($employee), 'type' => $this->type($r->leave_type_id, $reveal), 'status' => $r->status,
            'start_date' => $r->start_date, 'end_date' => $r->end_date, 'start_time' => $r->start_time ? substr($r->start_time, 0, 5) : null, 'end_time' => $r->end_time ? substr($r->end_time, 0, 5) : null,
            'timezone' => $r->timezone, 'portion' => $r->portion, 'unit' => $r->unit, 'requested_units' => Units::normalize($r->getRawOriginal('requested_units')), 'charged_units' => Units::normalize($r->getRawOriginal('charged_units')),
            'projected_balance' => $r->getRawOriginal('projected_balance') === null ? null : Units::normalize($r->getRawOriginal('projected_balance')),
            'reason' => $this->canSeeReason($viewer, $employee, (bool) $r->is_sensitive) ? $r->reason : null, 'reason_hidden' => (bool) $r->reason && ! $this->canSeeReason($viewer, $employee, (bool) $r->is_sensitive),
            'delegate' => $delegate ? $this->employee($delegate) : null, 'handover_notes' => $self || $hr || $this->approverView($viewer, $r, $employee) ? $r->handover_notes : null,
            'current_step' => (int) $r->current_step, 'total_steps' => (int) $r->total_steps, 'current_approver' => $pending ? $this->userName($r->approver_user_id) : null,
            'confidence' => $r->confidence, 'warnings' => $r->warnings ?? [], 'submitted_at' => $r->submitted_at?->toIso8601String(), 'decided_at' => $r->decided_at?->toIso8601String(), 'decided_by' => $this->userName($r->decided_by),
            'modification_of' => $r->parent_request_id ? LeaveRequest::query()->whereKey($r->parent_request_id)->value('uuid') : null,
            'can_decide' => $pending && $this->approvers->canDecide($viewer, $r, $employee),
            'can_withdraw' => $pending && ($r->requested_by === $viewer->id || $self),
            'can_cancel' => in_array($r->status, RequestService::OPEN, true) && ($self || $hr || ($viewer->allows('leave.approve') && $this->scope->isManagerOf($viewer, $employee))),
            'can_modify' => in_array($r->status, ['approved', 'partially_cancelled'], true) && ($self || $hr),
        ];
    }

    public function detail(LeaveRequest $r, User $viewer): array
    {
        $employee = $this->employees[$r->employee_id] ??= Employee::query()->with('person')->findOrFail($r->employee_id);
        $self = $this->scope->isSelf($viewer, $employee);
        $reveal = $this->canSeeSensitive($viewer, $employee);
        $out = $this->summary($r, $viewer);
        $out['segments'] = LeaveRequestSegment::query()->where('request_id', $r->id)->orderBy('leave_date')->orderBy('id')->get()->map(fn ($s) => [
            'date' => $s->leave_date, 'portion' => $s->portion, 'minutes' => (int) $s->minutes, 'units' => Units::normalize($s->getRawOriginal('units')), 'charged' => (bool) $s->charged, 'day_kind' => $s->day_kind,
            'pay_class' => $s->pay_class, 'status' => $s->status, 'starts_at' => $s->starts_at?->toIso8601String(), 'ends_at' => $s->ends_at?->toIso8601String(), 'explanation' => $s->explanation['text'] ?? null])->all();
        $out['explanation'] = $r->explanation['summary'] ?? [];
        $out['approval'] = ['roles' => $r->explanation['approval']['roles'] ?? [], 'escalation_reasons' => $r->explanation['approval']['escalated'] ?? false ? ($r->explanation['approval']['escalation_reasons'] ?? []) : ($r->explanation['approval']['escalation_reasons'] ?? []),
            'escalated' => (bool) ($r->explanation['approval']['escalated'] ?? false)];
        $out['conflicts'] = LeaveConflict::query()->where('request_id', $r->id)->orderBy('id')->get()->map(fn ($c) => ['kind' => $c->kind, 'severity' => $c->severity, 'message' => $c->message, 'resolved' => (bool) $c->resolved_at])->all();
        $out['history'] = $r->actions()->orderBy('id')->get()->map(fn ($a) => ['action' => $a->action, 'step' => $a->step, 'actor' => $this->userName($a->actor_user_id), 'from_status' => $a->from_status, 'to_status' => $a->to_status,
            'comment' => $r->is_sensitive && ! $reveal && in_array($a->action, ['submit', 'comment']) ? null : $a->comment, 'at' => $a->created_at?->toIso8601String()])->all();
        $docs = LeaveRequestDocument::query()->where('request_id', $r->id)->orderBy('id')->get();
        $out['documents'] = $docs->filter(fn ($d) => ! $d->is_sensitive || $reveal)->map(fn (LeaveRequestDocument $d) => ['uuid' => $d->uuid, 'kind' => $d->kind, 'filename' => $d->filename, 'mime' => $d->mime, 'size_bytes' => (int) $d->size_bytes, 'sensitive' => (bool) $d->is_sensitive])->values()->all();
        $out['documents_hidden'] = $docs->count() - count($out['documents']);
        if ($self || ($viewer->allows('leave.manage') && $this->scope->canAccess($viewer, $employee, 'leave.manage'))) {
            $out['ledger'] = LeaveLedgerEntry::query()->where('request_id', $r->id)->orderBy('id')->get()->map(fn ($e) => ['entry' => $e->uuid, 'type' => $e->entry_type, 'bucket' => $e->bucket, 'units' => Units::normalize($e->getRawOriginal('units')),
                'effective_date' => substr((string) $e->getRawOriginal('effective_date'), 0, 10), 'sources' => $this->sources($e->id)])->all();
        }
        $out['children'] = LeaveRequest::query()->where('parent_request_id', $r->id)->orderBy('id')->get(['uuid', 'status', 'start_date', 'end_date'])->map(fn ($c) => $c->only(['uuid', 'status', 'start_date', 'end_date']))->all();

        return $out;
    }

    private function sources(int $entryId): array
    {
        return DB::table('leave_ledger_allocations as a')->join('leave_ledger_entries as e', 'e.id', '=', 'a.source_entry_id')->where('a.entry_id', $entryId)
            ->get(['e.uuid', 'e.bucket', 'e.expires_on', 'a.units'])->map(fn ($x) => ['lot' => $x->uuid, 'bucket' => $x->bucket, 'expires_on' => $x->expires_on ? substr($x->expires_on, 0, 10) : null, 'units' => Units::normalize($x->units)])->all();
    }

    private function approverView(User $viewer, LeaveRequest $r, Employee $employee): bool
    {
        return $this->approvers->candidatesFor($r, $employee)->contains('id', $viewer->id) || $this->scope->isManagerOf($viewer, $employee);
    }
}
