<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\InAppNotification;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    public function notifications(Request $r)
    {
        $q = InAppNotification::where('user_id', $r->user()->id);
        if ($r->input('filter') === 'unread') {
            $q->whereNull('read_at');
        }
        if ($r->input('filter') === 'read') {
            $q->whereNotNull('read_at');
        }
        if ($r->filled('category')) {
            $q->where('category', $r->category);
        }

        return $q->latest()->paginate(20);
    }

    public function read(Request $r, ?int $id = null)
    {
        $q = InAppNotification::where('user_id', $r->user()->id);
        if ($id) {
            $q->findOrFail($id)->update(['read_at' => now()]);
        } else {
            $q->whereNull('read_at')->update(['read_at' => now()]);
        }

        return response()->noContent();
    }

    public function audit(Request $r)
    {
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $q = AuditLog::query();
        foreach (['action', 'module', 'resource', 'actor', 'ip_address'] as $field) {
            if ($r->filled($field)) {
                $q->where($field, 'like', '%'.substr($r->input($field), 0, 100).'%');
            }
        }
        if ($r->filled('from')) {
            $q->whereDate('created_at', '>=', $r->from);
        }
        if ($r->filled('to')) {
            $q->whereDate('created_at', '<=', $r->to);
        }

        return $q->orderByDesc('id')->paginate(20);
    }

    public function dashboard(Request $r)
    {
        $u = $r->user();
        $modules = $u->tenant->settings['modules'] ?? ['work', 'notifications'];
        $work = $u->allows('work.view') && in_array('work', $modules);
        $tasks = WorkItem::where('assignee_id', $u->id)->where('status', 'open');
        $today = now($u->tenant->settings['timezone'] ?? config('app.timezone'))->toDateString();

        return ['open_tasks' => $work ? (clone $tasks)->count() : null, 'overdue_tasks' => $work ? (clone $tasks)->whereDate('due_date', '<', $today)->count() : null,
            'people' => $u->allows('users.view') ? User::where('tenant_id', $u->tenant_id)->where('active', true)->count() : null,
            'my_work' => $work ? (clone $tasks)->orderByRaw('due_date IS NULL')->orderBy('due_date')->limit(5)->get() : [],
            'unread' => in_array('notifications', $modules) ? InAppNotification::where('user_id', $u->id)->whereNull('read_at')->count() : 0,
            'announcements' => Announcement::latest()->limit(5)->get(),
            'attendance' => null, 'people_away' => [], 'events' => [], 'pending_approvals' => [],
            'alerts' => $u->tenant->setup_completed_at ? [] : [['title' => 'Finish your company setup', 'body' => 'Your organization details are still in progress.', 'action' => $u->allows('settings.manage') ? '/setup' : null]]];
    }
}
