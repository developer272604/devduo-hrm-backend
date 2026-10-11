<?php

namespace App\Http\Controllers\Experience;

use App\Domain\Access\DataScope;
use App\Domain\Experience\{HelpdeskService, KnowledgeService};
use App\Domain\Experience\Models\{KnowledgeArticle, ServiceCategory, Ticket};
use App\Domain\People\Models\Employee;
use App\Domain\Leave\Accrual\Actors;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class HelpdeskController
{
    public function __construct(private HelpdeskService $helpdesk, private KnowledgeService $knowledge, private DataScope $scope) {}

    private function find(Request $r, string $uuid): Ticket
    {
        return $this->helpdesk->visibleTickets($r->user())->where('uuid', $uuid)->firstOrFail();
    }

    public function categories(Request $r)
    {
        return $this->helpdesk->categories()->map(fn ($c) => ['uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'description' => $c->description,
            'default_assignee' => $r->user()->allows('experience.category.manage') && $c->default_assignee_id ? User::whereKey($c->default_assignee_id)->value('name') : null]);
    }

    public function health() { return $this->helpdesk->health(); }

    public function assignees()
    {
        return User::whereIn('id', Actors::usersWithPermission('experience.ticket.manage'))->where('active', true)
            ->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values();
    }

    public function createCategory(Request $r)
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:40', Rule::unique('experience_service_categories', 'code')->where('tenant_id', $r->user()->tenant_id)], 'name' => 'required|string|max:120', 'description' => 'nullable|string|max:2000', 'target_hours' => 'nullable|integer|min:1|max:8760',
            'default_assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $r->user()->tenant_id)]]);
        $category = $this->helpdesk->createCategory($d);
        return response()->json(['uuid' => $category->uuid, 'code' => $category->code, 'name' => $category->name, 'description' => $category->description, 'target_hours' => $category->target_hours, 'default_assignee_id' => $category->default_assignee_id], 201);
    }

    public function index(Request $r)
    {
        $q = $this->helpdesk->visibleTickets($r->user())->with('category')->latest('id');
        if ($r->filled('status')) $q->where('status', $r->query('status'));
        if ($r->filled('category_uuid')) $q->whereIn('category_id', ServiceCategory::where('uuid', $r->query('category_uuid'))->select('id'));
        if ($r->filled('priority')) $q->where('priority', $r->query('priority'));
        if ($r->query('assignee') === 'unassigned') $q->whereNull('assignee_id');
        elseif ($r->filled('assignee') && ctype_digit((string) $r->query('assignee'))) $q->where('assignee_id', (int) $r->query('assignee'));
        if ($r->boolean('sla_overdue')) $q->whereIn('status', ['open', 'in_progress', 'waiting_employee'])->whereNotNull('sla_due_at')->where('sla_due_at', '<=', now());
        return $q->paginate(min(100, max(1, (int) $r->query('per_page', 25))))->through(fn ($ticket) => $this->helpdesk->present($ticket, $r->user()));
    }

    public function show(Request $r, string $uuid)
    {
        return $this->helpdesk->present($this->find($r, $uuid), $r->user());
    }

    public function attachment(Request $r, string $uuid, string $attachment)
    {
        $ticket = $this->find($r, $uuid);
        $file = $this->helpdesk->attachment($ticket, $attachment);
        abort_unless(Storage::disk('experience_private')->exists($file->path), 404);
        return Storage::disk('experience_private')->download($file->path, $file->filename, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function addAttachment(Request $r, string $uuid)
    {
        $data = $r->validate(['file' => 'required|file|mimes:pdf,png,jpg,jpeg,doc,docx,xlsx|max:5120']);
        $ticket = $this->find($r, $uuid);
        $attachment = $this->helpdesk->addAttachment($r->user(), $ticket, $data['file']);
        return response()->json(['uuid' => $attachment->uuid, 'filename' => $attachment->filename, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes], 201);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['category_uuid' => 'required|uuid', 'subject' => 'required|string|max:200', 'description' => 'required|string|max:10000']);
        $employeeId = $this->scope->ownEmployeeIds($r->user())[0] ?? abort(403, 'No employee profile is linked to this account.');
        $employee = Employee::findOrFail($employeeId);
        $ticket = $this->helpdesk->create($r->user(), $employee, $d);
        return response()->json($this->helpdesk->present($ticket, $r->user()), 201);
    }

    public function comment(Request $r, string $uuid)
    {
        $d = $r->validate(['body' => 'required|string|max:10000', 'visibility' => ['sometimes', Rule::in(['public', 'private'])]]);
        $ticket = $this->find($r, $uuid);
        $this->helpdesk->comment($r->user(), $ticket, $d['body'], $d['visibility'] ?? 'public');
        return $this->helpdesk->present($ticket->refresh(), $r->user());
    }

    public function update(Request $r, string $uuid)
    {
        $d = $r->validate(['status' => ['sometimes', Rule::in(['in_progress', 'waiting_employee', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])], 'assignee_id' => 'sometimes|nullable|integer', 'resolution' => 'sometimes|nullable|string|max:1000']);
        return $this->helpdesk->present($this->helpdesk->update($r->user(), $this->find($r, $uuid), $d), $r->user());
    }

    public function reopen(Request $r, string $uuid)
    {
        return $this->helpdesk->present($this->helpdesk->reopenOwn($r->user(), $this->find($r, $uuid)), $r->user());
    }

    public function feedback(Request $r, string $uuid)
    {
        $d = $r->validate(['rating' => 'required|integer|between:1,5', 'comment' => 'nullable|string|max:1000']);
        $ticket = $this->find($r, $uuid);
        $this->helpdesk->feedback($r->user(), $ticket, $d['rating'], $d['comment'] ?? null);
        return $this->helpdesk->present($ticket->refresh(), $r->user());
    }

    public function escalate(Request $r, string $uuid)
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);
        $ticket = $this->find($r, $uuid);
        $this->helpdesk->escalate($r->user(), $ticket, $d['reason']);
        return $this->helpdesk->present($ticket->refresh(), $r->user());
    }

    public function knowledgeIndex(Request $r)
    {
        $q = $this->knowledge->visible($r->user());
        if ($r->filled('locale')) $q->where('locale', $r->query('locale'));
        if ($r->filled('search')) {
            $term = trim(str_replace(['%', '_'], '', (string) $r->query('search')));
            if ($term !== '') $q->where(fn ($q) => $q->where('title', 'like', '%'.$term.'%')->orWhere('summary', 'like', '%'.$term.'%')->orWhere('body', 'like', '%'.$term.'%'));
        }
        return $q->orderByDesc('effective_from')->orderBy('title')->paginate(min(50, max(1, (int) $r->query('per_page', 20))))
            ->through(fn ($article) => $this->knowledge->present($article, $r->user()));
    }

    public function knowledgeShow(Request $r, string $uuid)
    {
        return $this->knowledge->present($this->knowledge->visible($r->user())->where('uuid', $uuid)->firstOrFail(), $r->user());
    }

    public function knowledgeCreate(Request $r)
    {
        $d = $r->validate(['article_key' => 'nullable|string|max:180', 'locale' => ['required', 'string', 'max:12', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/'],
            'title' => 'required|string|max:200', 'summary' => 'nullable|string|max:500', 'body' => 'required|string|max:50000',
            'visibility' => ['sometimes', Rule::in(['employee', 'hr'])], 'effective_from' => 'nullable|date_format:Y-m-d', 'effective_until' => 'nullable|date_format:Y-m-d']);
        return response()->json($this->knowledge->present($this->knowledge->create($r->user(), $d)), 201);
    }

    public function knowledgePublish(Request $r, string $uuid)
    {
        $article = KnowledgeArticle::where('uuid', $uuid)->firstOrFail();
        return $this->knowledge->present($this->knowledge->publish($article, $r->user()));
    }

    public function knowledgeFeedback(Request $r, string $uuid)
    {
        $d = $r->validate(['helpful' => 'required|boolean']);
        $article = $this->knowledge->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        $this->knowledge->feedback($article, $r->user(), $d['helpful']);
        return $this->knowledge->present($article, $r->user());
    }

    public function knowledgeFeedbackResults(Request $r, string $uuid)
    {
        $article = KnowledgeArticle::where('uuid', $uuid)->firstOrFail();
        return $this->knowledge->feedbackResults($article);
    }
}
