<?php

namespace App\Domain\Experience;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Experience\Models\{ServiceCategory, Ticket, TicketComment, TicketFeedback};
use App\Domain\Experience\Models\TicketAttachment;
use App\Domain\Experience\Models\TicketEscalation;
use App\Domain\People\Models\Employee;
use App\Domain\Leave\Accrual\Actors;
use App\Domain\Notifications\NotificationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class HelpdeskService
{
    public function __construct(private DataScope $scope) {}

    public function categories()
    {
        if (! ServiceCategory::exists()) {
            foreach ([['PAYROLL', 'Payroll'], ['BENEFITS', 'Benefits'], ['LEAVE', 'Leave'], ['WORKPLACE', 'Workplace'], ['OTHER', 'Other']] as [$code, $name]) {
                ServiceCategory::firstOrCreate(['code' => $code], ['name' => $name, 'active' => true]);
            }
        }
        return ServiceCategory::where('active', true)->orderBy('name')->get();
    }

    public function health(): array
    {
        $statuses = Ticket::select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
        $unresolved = Ticket::whereIn('status', ['open', 'in_progress', 'waiting_employee']);
        $oldest = (clone $unresolved)->oldest('created_at')->value('created_at');
        $overdue = (clone $unresolved)->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count();
        $feedback = TicketFeedback::query();
        $feedbackCount = $feedback->count();
        return ['backlog' => ['open' => (int) ($statuses['open'] ?? 0), 'in_progress' => (int) ($statuses['in_progress'] ?? 0),
            'waiting_employee' => (int) ($statuses['waiting_employee'] ?? 0), 'total' => (int) $statuses->only(['open', 'in_progress', 'waiting_employee'])->sum()],
            'sla_overdue' => $overdue, 'oldest_open_age_days' => $oldest ? Carbon::parse($oldest)->diffInDays(now()) : 0,
            'satisfaction' => $feedbackCount < 5 ? ['suppressed' => true, 'minimum_responses' => 5] : ['suppressed' => false, 'response_count' => $feedbackCount, 'average_rating' => round((float) $feedback->avg('rating'), 2)]];
    }

    public function visibleTickets(User $user)
    {
        if ($user->allows('experience.ticket.manage') && $this->scope->hasTenantWide($user, 'experience.ticket.manage')) return Ticket::query();
        $ids = $this->scope->ownEmployeeIds($user);
        if ($user->allows('experience.view')) {
            $employees = $this->scope->constrain(DB::table('employees')->where('employees.tenant_id', $user->tenant_id)->whereNull('employees.deleted_at'), $user, 'experience.view')->pluck('employees.id')->all();
            $ids = array_values(array_unique([...$ids, ...$employees]));
        }
        return Ticket::whereIn('employee_id', $ids ?: [0]);
    }

    private function canManageTicket(User $user, Ticket $ticket): bool
    {
        return $user->allows('experience.ticket.manage') && ($this->scope->hasTenantWide($user, 'experience.ticket.manage') || $this->scope->canAccess($user, $ticket->employee_id, 'experience.ticket.manage'));
    }

    public function present(Ticket $ticket, User $viewer): array
    {
        $ticket->loadMissing('category', 'comments', 'attachments');
        $private = $this->canManageTicket($viewer, $ticket);
        $employee = Employee::with('person')->find($ticket->employee_id);
        $comments = $ticket->comments->filter(fn ($c) => $private || $c->visibility === 'public')->map(fn ($c) => [
            'uuid' => $c->uuid, 'body' => $c->body, 'visibility' => $c->visibility,
            'author' => User::whereKey($c->author_id)->value('name'), 'created_at' => $c->created_at?->toIso8601String(),
        ])->values()->all();
        return [
            'uuid' => $ticket->uuid, 'subject' => $ticket->subject, 'description' => $ticket->description,
            'category' => ['uuid' => $ticket->category?->uuid, 'name' => $ticket->category?->name],
            'employee' => ['uuid' => $employee?->uuid, 'name' => trim(($employee?->person?->legal_first_name ?? '').' '.($employee?->person?->legal_last_name ?? ''))],
            'assignee_id' => $ticket->assignee_id, 'priority' => $ticket->priority,
            'status' => $ticket->status, 'resolution' => $ticket->resolution, 'sla_due_at' => $ticket->sla_due_at?->toIso8601String(), 'first_response_at' => $ticket->first_response_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(), 'created_at' => $ticket->created_at?->toIso8601String(), 'comments' => $comments,
            'attachments' => $ticket->attachments->map(fn ($a) => ['uuid' => $a->uuid, 'filename' => $a->filename, 'mime_type' => $a->mime_type, 'size_bytes' => $a->size_bytes])->values()->all(),
            'escalations' => $private ? $ticket->escalations->map(fn ($e) => ['uuid' => $e->uuid, 'type' => $e->escalation_type, 'level' => $e->level, 'reason' => $e->reason, 'created_at' => $e->created_at?->toIso8601String()])->values()->all() : [],
            'can_reopen' => in_array($ticket->employee_id, $this->scope->ownEmployeeIds($viewer), true) && $ticket->status === 'resolved',
            'can_feedback' => in_array($ticket->employee_id, $this->scope->ownEmployeeIds($viewer), true) && in_array($ticket->status, ['resolved', 'closed'], true)
                && ! TicketFeedback::where('ticket_id', $ticket->id)->exists(),
        ];
    }

    public function reopenOwn(User $actor, Ticket $ticket): Ticket
    {
        abort_unless(in_array($ticket->employee_id, $this->scope->ownEmployeeIds($actor), true), 403, 'Only the requester can reopen this ticket.');
        if (! in_array($ticket->status, ['resolved', 'closed'], true)) throw ValidationException::withMessages(['status' => 'Only a resolved or closed ticket can be reopened.']);
        $ticket->update(['status' => 'in_progress', 'resolved_at' => null, 'resolution' => null]);
        Audit::record('ticket.reopened', 'experience', 'ticket:'.$ticket->uuid);
        return $ticket->refresh();
    }

    public function feedback(User $actor, Ticket $ticket, int $rating, ?string $comment): TicketFeedback
    {
        $employeeId = $this->scope->ownEmployeeIds($actor)[0] ?? null;
        abort_unless($employeeId && $ticket->employee_id === $employeeId, 403, 'Only the requester can rate this ticket.');
        if (! in_array($ticket->status, ['resolved', 'closed'], true)) throw ValidationException::withMessages(['ticket' => 'Feedback is available after the ticket is resolved.']);
        if (TicketFeedback::where('ticket_id', $ticket->id)->exists()) throw ValidationException::withMessages(['ticket' => 'Feedback has already been submitted.']);
        $feedback = TicketFeedback::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'ticket_id' => $ticket->id,
            'employee_id' => $employeeId, 'rating' => $rating, 'comment' => $comment]);
        Audit::record('ticket.feedback_submitted', 'experience', 'ticket:'.$ticket->uuid, ['rating' => $rating]);
        return $feedback;
    }

    public function create(User $actor, Employee $employee, array $data): Ticket
    {
        $category = ServiceCategory::where('uuid', $data['category_uuid'])->where('active', true)->firstOrFail();
        $ticket = Ticket::create([
            'employee_id' => $employee->id, 'category_id' => $category->id, 'requester_id' => $actor->id, 'assignee_id' => $category->default_assignee_id,
            'subject' => $data['subject'], 'description' => $data['description'], 'priority' => 'normal', 'status' => 'open',
            'sla_due_at' => $category->target_hours ? now()->addHours($category->target_hours) : null,
        ]);
        Audit::record('ticket.created', 'experience', 'ticket:'.$ticket->uuid, ['category' => $category->code]);
        return $ticket;
    }

    public function addAttachment(User $actor, Ticket $ticket, UploadedFile $file): TicketAttachment
    {
        if ($ticket->attachments()->count() >= 5) throw ValidationException::withMessages(['attachment' => 'A ticket can have at most five attachments.']);
        $uuid = (string) Str::uuid();
        $path = 'tenant/'.$actor->tenant_id.'/tickets/'.$ticket->uuid.'/'.$uuid.'.'.$file->extension();
        Storage::disk('experience_private')->put($path, file_get_contents($file->getRealPath()));
        try {
            $attachment = TicketAttachment::create(['uuid' => $uuid, 'ticket_id' => $ticket->id, 'uploaded_by' => $actor->id,
                'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(), 'path' => $path]);
        } catch (\Throwable $e) {
            Storage::disk('experience_private')->delete($path);
            throw $e;
        }
        Audit::record('ticket.attachment_added', 'experience', 'ticket:'.$ticket->uuid);
        return $attachment;
    }

    public function attachment(Ticket $ticket, string $uuid): TicketAttachment
    {
        return $ticket->attachments()->where('uuid', $uuid)->firstOrFail();
    }

    public function escalate(User $actor, Ticket $ticket, string $reason): TicketEscalation
    {
        abort_unless($this->canManageTicket($actor, $ticket), 403, 'You cannot escalate this ticket.');
        if (in_array($ticket->status, ['resolved', 'closed'], true)) throw ValidationException::withMessages(['ticket' => 'Only active tickets can be escalated.']);
        return DB::transaction(function () use ($actor, $ticket, $reason) {
            $level = (int) $ticket->escalations()->max('level') + 1;
            $record = TicketEscalation::create(['uuid' => (string) Str::uuid(), 'ticket_id' => $ticket->id, 'escalation_type' => 'manual',
                'level' => $level, 'dedupe_key' => 'manual:'.Str::uuid(), 'created_by' => $actor->id, 'reason' => $reason]);
            if ($ticket->priority !== 'urgent') $ticket->update(['priority' => $ticket->priority === 'high' ? 'urgent' : 'high']);
            $notified = $this->notifyEscalationRecipients($ticket, [$actor->id]);
            if ($notified) $record->update(['notified_at' => now()]);
            Audit::record('ticket.escalated', 'experience', 'ticket:'.$ticket->uuid, ['type' => 'manual', 'level' => $level]);
            return $record;
        });
    }

    /** Escalate overdue unresolved tickets once, and retry notifications if no service owner was available. */
    public function escalateSlaBreaches(): int
    {
        $tickets = Ticket::whereIn('status', ['open', 'in_progress', 'waiting_employee'])->whereNotNull('sla_due_at')->where('sla_due_at', '<=', now())
            ->where(fn ($q) => $q->whereDoesntHave('escalations', fn ($e) => $e->where('escalation_type', 'sla_breach')->whereNotNull('notified_at')))->orderBy('sla_due_at')->limit(1000)->get();
        $created = 0;
        foreach ($tickets as $ticket) {
            DB::transaction(function () use ($ticket, &$created) {
                $locked = Ticket::whereKey($ticket->id)->lockForUpdate()->first();
                if (! $locked || ! in_array($locked->status, ['open', 'in_progress', 'waiting_employee'], true) || ! $locked->sla_due_at?->lte(now())) return;
                $record = TicketEscalation::firstOrCreate(['ticket_id' => $locked->id, 'dedupe_key' => 'sla_breach'], [
                    'uuid' => (string) Str::uuid(), 'escalation_type' => 'sla_breach', 'level' => 1,
                    'reason' => 'The ticket response target has been breached.', 'created_by' => null,
                ]);
                if ($record->wasRecentlyCreated) {
                    $created++;
                    if ($locked->priority !== 'urgent') $locked->update(['priority' => 'urgent']);
                    Audit::record('ticket.sla_breached', 'experience', 'ticket:'.$locked->uuid, ['level' => 1]);
                }
                if (! $record->notified_at && $this->notifyEscalationRecipients($locked)) $record->update(['notified_at' => now()]);
            });
        }
        return $created;
    }

    private function notifyEscalationRecipients(Ticket $ticket, array $exclude = []): bool
    {
        $recipients = Actors::usersWithPermission('experience.ticket.manage', $exclude);
        $notified = false;
        $sent = [];
        foreach (array_unique($recipients) as $userId) {
            $user = User::where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('active', true)->find($userId);
            if (! $user || ! $user->allows('experience.ticket.manage') || ! $this->scope->hasTenantWide($user, 'experience.ticket.manage')) continue;
            app(NotificationService::class)->send($user->id, 'HR ticket escalated', 'An HR service ticket requires attention. Open the service desk queue.', 'experience.ticket', '/experience', true);
            $notified = true;
            $sent[] = $user->id;
        }
        if ($ticket->assignee_id && ! in_array($ticket->assignee_id, $exclude, true)) {
            $assignee = User::where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('active', true)->find($ticket->assignee_id);
            $assigneeCanView = $assignee && $assignee->allows('experience.ticket.manage')
                && ($this->scope->hasTenantWide($assignee, 'experience.ticket.manage') || $this->scope->canAccess($assignee, $ticket->employee_id, 'experience.ticket.manage'));
            if ($assigneeCanView && ! in_array($assignee->id, $sent, true)) {
                app(NotificationService::class)->send($assignee->id, 'HR ticket escalated', 'A ticket assigned to you requires attention.', 'experience.ticket', '/experience', true);
                $notified = true;
            }
        }
        return $notified;
    }

    public function comment(User $actor, Ticket $ticket, string $body, string $visibility = 'public'): TicketComment
    {
        abort_unless($visibility === 'public' || $this->canManageTicket($actor, $ticket), 403, 'Only HR service staff with ticket access can add private notes.');
        if ($ticket->status === 'closed') throw ValidationException::withMessages(['ticket' => 'A closed ticket cannot receive comments.']);
        $comment = TicketComment::create(['ticket_id' => $ticket->id, 'author_id' => $actor->id, 'visibility' => $visibility, 'body' => $body]);
        if ($visibility === 'public' && $this->canManageTicket($actor, $ticket) && ! $ticket->first_response_at) $ticket->update(['first_response_at' => now()]);
        Audit::record($visibility === 'private' ? 'ticket.private_note_added' : 'ticket.comment_added', 'experience', 'ticket:'.$ticket->uuid);
        return $comment;
    }

    public function update(User $actor, Ticket $ticket, array $data): Ticket
    {
        abort_unless($this->canManageTicket($actor, $ticket), 403, 'You cannot manage this ticket.');
        $before = $ticket->status;
        if (isset($data['status'])) {
            $allowed = match ($before) {
                'open' => ['in_progress', 'waiting_employee', 'resolved'],
                'in_progress' => ['waiting_employee', 'resolved'],
                'waiting_employee' => ['in_progress', 'resolved'],
                'resolved' => ['closed', 'in_progress'],
                default => [],
            };
            if (! in_array($data['status'], $allowed, true)) throw ValidationException::withMessages(['status' => 'That status change is not allowed.']);
            $ticket->status = $data['status'];
            $ticket->resolved_at = $data['status'] === 'resolved' ? now() : ($data['status'] === 'in_progress' ? null : $ticket->resolved_at);
            $ticket->closed_at = $data['status'] === 'closed' ? now() : null;
        }
        if (array_key_exists('assignee_id', $data)) {
            if ($data['assignee_id'] && ! User::where('tenant_id', $actor->tenant_id)->where('active', true)->whereKey($data['assignee_id'])->exists()) {
                throw ValidationException::withMessages(['assignee_id' => 'Choose an active user in this workspace.']);
            }
            $ticket->assignee_id = $data['assignee_id'];
        }
        if (isset($data['priority'])) $ticket->priority = $data['priority'];
        if (array_key_exists('resolution', $data)) $ticket->resolution = $data['resolution'];
        $ticket->save();
        Audit::record('ticket.updated', 'experience', 'ticket:'.$ticket->uuid, ['from' => $before, 'to' => $ticket->status]);
        return $ticket->refresh();
    }

    public function createCategory(array $data): ServiceCategory
    {
        if (! empty($data['default_assignee_id'])) {
            $assignee = User::where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('active', true)->findOrFail($data['default_assignee_id']);
            if (! $assignee->allows('experience.ticket.manage')) throw ValidationException::withMessages(['default_assignee_id' => 'Choose an active HR service user with ticket management access.']);
        }
        return ServiceCategory::create(['uuid' => (string) Str::uuid(), 'code' => Str::upper($data['code']), 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'active' => true, 'target_hours' => $data['target_hours'] ?? null, 'default_assignee_id' => $data['default_assignee_id'] ?? null]);
    }
}
