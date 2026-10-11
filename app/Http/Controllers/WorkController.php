<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use App\Domain\Work\TaskService;
use App\Models\WorkItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkController extends Controller
{
    private function scoped(Request $r)
    {
        $q = WorkItem::query();
        if (! $r->user()->hasTenantScope()) {
            $q->where('assignee_id', $r->user()->id);
        }

        return $q;
    }

    public function index(Request $r)
    {
        $q = $this->scoped($r);
        $tab = $r->input('tab', 'open');
        $today = now($r->user()->tenant->settings['timezone'] ?? config('app.timezone'))->startOfDay();
        $q->where('status', $tab === 'completed' ? 'completed' : 'open');
        if ($tab === 'due-soon') {
            $q->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()]);
        }
        if ($tab === 'overdue') {
            $q->whereDate('due_date', '<', $today->toDateString());
        }
        if ($r->filled('search')) {
            $q->where('title', 'like', '%'.substr($r->search, 0, 100).'%');
        }
        if ($r->filled('priority')) {
            $q->where('priority', $r->priority);
        }
        if ($r->filled('source_module')) {
            $q->where('source_module', $r->source_module);
        }

        return $q->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderByDesc('id')->paginate(20);
    }

    public function store(Request $r, TaskService $service)
    {
        $data = $r->validate(['title' => 'required|string|max:200', 'description' => 'nullable|string|max:2000', 'priority' => 'required|in:low,normal,high,urgent', 'due_date' => 'nullable|date_format:Y-m-d', 'assignee_id' => 'required|integer']);
        if (! $r->user()->hasTenantScope()) {
            abort_unless((int) $data['assignee_id'] === $r->user()->id, 403);
        }

        return response()->json($service->create($data), 201);
    }

    public function update(Request $r, int $id)
    {
        $task = $this->scoped($r)->findOrFail($id);
        abort_if($task->source_module === 'workflows', 422, 'Open the linked workflow to complete or decide this task.');
        abort_if(isset($task->metadata['approval_request_id']), 422, 'Open the linked request to approve or reject it. Approval tasks cannot be completed manually.');
        $data = $r->validate(['status' => 'required|in:open,completed']);

        return DB::transaction(function () use ($task, $data) {
            $task->update(['status' => $data['status'], 'completed_at' => $data['status'] === 'completed' ? now() : null]);
            Audit::record($data['status'] === 'completed' ? 'completed' : 'reopened', 'work', 'task:'.$task->id);

            return $task;
        });
    }
}
