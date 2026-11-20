<?php

namespace App\Http\Controllers;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProjectsController
{
    private function manage(User $user): bool
    {
        return $user->allows('projects.manage') && ($user->is_owner || app(DataScope::class)->hasTenantWide($user, 'projects.manage'));
    }

    private function projects(User $user): Builder
    {
        return DB::table('work_projects')->where('tenant_id', $user->tenant_id);
    }

    private function sheets(User $user): Builder
    {
        return DB::table('work_timesheets')->where('work_timesheets.tenant_id', $user->tenant_id);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $projects = $this->projects($user);
        if (! $this->manage($user) || $request->boolean('assigned')) {
            $projects->whereIn('id', DB::table('work_project_members')->where('user_id', $user->id)->select('project_id'));
        }

        return $projects->orderBy('name')->orderBy('id')->paginate(25, ['uuid', 'name']);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($this->manage($user), 403);
        $data = $request->validate(['name' => 'required|string|max:150']);
        $project = ['uuid' => (string) Str::uuid(), 'name' => $data['name']];
        DB::transaction(function () use ($user, $project) {
            DB::table('work_projects')->insert($project + ['tenant_id' => $user->tenant_id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('project.created', 'work', 'project:'.$project['uuid']);
        });

        return response()->json($project, 201);
    }

    public function people(Request $request)
    {
        $user = $request->user();
        abort_unless($this->manage($user), 403);
        $data = $request->validate(['project' => 'required|uuid', 'q' => 'nullable|string|max:100']);
        $project = $this->projects($user)->where('uuid', $data['project'])->first();
        abort_unless($project, 404);

        return DB::table('users')->where('tenant_id', $user->tenant_id)->where('active', true)
            ->when($data['q'] ?? null, fn ($query, $name) => $query->where('name', 'like', '%'.$name.'%'))
            ->leftJoin('work_project_members', function ($join) use ($project) {
                $join->on('users.id', '=', 'work_project_members.user_id')->where('work_project_members.project_id', $project->id);
            })->orderBy('users.name')->orderBy('users.id')->paginate(25, ['users.id', 'users.name', 'work_project_members.project_id'])
            ->through(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'assigned' => $row->project_id !== null]);
    }

    public function members(Request $request, string $project)
    {
        $user = $request->user();
        abort_unless($this->manage($user), 403);
        $data = $request->validate(['user_id' => 'required|integer', 'assigned' => 'required|boolean']);

        DB::transaction(function () use ($user, $project, $data) {
            $row = $this->projects($user)->where('uuid', $project)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(DB::table('users')->where('tenant_id', $user->tenant_id)->where('id', $data['user_id'])->where('active', true)->exists(), 404);
            $member = ['project_id' => $row->id, 'user_id' => $data['user_id']];
            if ($data['assigned']) {
                DB::table('work_project_members')->insertOrIgnore($member);
            } else {
                DB::table('work_project_members')->where($member)->delete();
            }
            Audit::record('project.membership_changed', 'work', 'project:'.$project);
        });

        return response()->noContent();
    }

    public function task(Request $request, string $project, TaskService $tasks)
    {
        $user = $request->user();
        abort_unless($this->manage($user) && $user->allows('work.manage'), 403);
        $data = $request->validate([
            'assignee_id' => 'required|integer',
            'title' => 'required|string|max:200',
            'due_date' => 'nullable|date_format:Y-m-d',
        ]);

        return DB::transaction(function () use ($user, $project, $data, $tasks) {
            $row = $this->projects($user)->where('uuid', $project)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(DB::table('work_project_members')->where('project_id', $row->id)->where('user_id', $data['assignee_id'])->exists(), 422, 'Assign the user to this project before creating a task.');
            $task = $tasks->create($data + ['source_module' => 'projects', 'source_entity' => $project, 'priority' => 'normal']);

            return response()->json($task, 201);
        });
    }

    private function week(Request $request): CarbonImmutable
    {
        $date = $request->validate(['week_start' => 'required|date_format:Y-m-d'])['week_start'];
        $week = CarbonImmutable::parse($date);
        if (! $week->isMonday()) {
            throw ValidationException::withMessages(['week_start' => 'Choose the Monday of the reporting week.']);
        }

        return $week;
    }

    private function present(object $row): array
    {
        return [
            'uuid' => $row->uuid,
            'week_start' => $row->week_start,
            'status' => $row->status,
            'version' => (int) $row->version,
            'entries' => json_decode($row->entries, true, 512, JSON_THROW_ON_ERROR),
            'review_note' => $row->review_note,
            'name' => $row->name ?? null,
        ];
    }

    public function own(Request $request)
    {
        $week = $this->week($request)->toDateString();
        $user = $request->user();
        $row = $this->sheets($user)->where('user_id', $user->id)->where('week_start', $week)->first();

        return $row ? $this->present($row) : ['week_start' => $week, 'status' => 'draft', 'version' => 0, 'entries' => [], 'review_note' => null];
    }

    public function save(Request $request)
    {
        $week = $this->week($request);
        $user = $request->user();
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'submit' => 'required|boolean',
            'entries' => 'present|array|max:100',
            'entries.*' => 'required|array:project,date,minutes,billable,note',
            'entries.*.project' => 'required|uuid',
            'entries.*.date' => 'required|date_format:Y-m-d',
            'entries.*.minutes' => 'required|integer|between:1,1440',
            'entries.*.billable' => 'required|boolean',
            'entries.*.note' => 'nullable|string|max:300',
        ]);
        $totals = [];
        foreach ($data['entries'] as $entry) {
            $date = $entry['date'];
            $totals[$date] = ($totals[$date] ?? 0) + $entry['minutes'];
            if ($date < $week->toDateString() || $date > $week->addDays(6)->toDateString() || $totals[$date] > 1440) {
                throw ValidationException::withMessages(['entries' => 'Use dates in the selected week and at most 24 hours per day.']);
            }
        }
        if ($data['submit'] && $data['entries'] === []) {
            throw ValidationException::withMessages(['entries' => 'Add at least one time entry before submitting.']);
        }

        return DB::transaction(function () use ($user, $week, $data) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $query = $this->sheets($user)->where('user_id', $user->id)->where('week_start', $week->toDateString());
            $row = (clone $query)->lockForUpdate()->first();
            abort_if($row && ! in_array($row->status, ['draft', 'rejected'], true), 409, 'Submitted or approved timesheets are read-only.');
            abort_unless((int) ($row->version ?? 0) === (int) $data['version'], 409, 'The timesheet changed. Reload it before saving.');
            $uuids = array_unique(array_column($data['entries'], 'project'));
            $projects = $this->projects($user)->whereIn('uuid', $uuids)->orderBy('id')->lockForUpdate()->get()->keyBy('uuid');
            $assigned = DB::table('work_project_members')->where('user_id', $user->id)->whereIn('project_id', $projects->pluck('id'))->pluck('project_id')->all();
            $entries = [];
            foreach ($data['entries'] as $entry) {
                $project = $projects->get($entry['project']);
                if (! $project || ! in_array($project->id, $assigned, true)) {
                    throw ValidationException::withMessages(['entries' => 'Each entry must use a project currently assigned to you.']);
                }
                $entries[] = array_replace($entry, ['project_name' => $project->name, 'minutes' => (int) $entry['minutes'], 'billable' => (bool) $entry['billable']]);
            }
            $values = [
                'entries' => json_encode($entries, JSON_THROW_ON_ERROR),
                'status' => $data['submit'] ? 'submitted' : 'draft',
                'version' => $data['version'] + 1,
                'reviewer_id' => null,
                'review_note' => null,
                'updated_at' => now(),
            ];
            $uuid = $row->uuid ?? (string) Str::uuid();
            if ($row) {
                $query->update($values);
            } else {
                DB::table('work_timesheets')->insert($values + ['tenant_id' => $user->tenant_id, 'uuid' => $uuid, 'user_id' => $user->id, 'week_start' => $week->toDateString(), 'created_at' => now()]);
            }
            if ($data['submit']) {
                Audit::record('timesheet.submitted', 'work', 'timesheet:'.$uuid);
            }

            return $this->present($query->first());
        }, 3);
    }

    public function reviews(Request $request)
    {
        $user = $request->user();
        abort_unless($this->manage($user), 403);

        return $this->sheets($user)->where('status', 'submitted')->where('work_timesheets.user_id', '!=', $user->id)
            ->join('users', 'users.id', '=', 'work_timesheets.user_id')->orderBy('week_start')->orderBy('work_timesheets.id')
            ->paginate(15, ['work_timesheets.*', 'users.name'])->through(fn ($row) => $this->present($row));
    }

    public function review(Request $request, string $sheet)
    {
        $user = $request->user();
        abort_unless($this->manage($user), 403);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'note' => 'required_if:decision,rejected|nullable|string|max:500', 'version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($user, $sheet, $data) {
            $query = $this->sheets($user)->where('uuid', $sheet);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if((int) $row->user_id === $user->id, 403, 'You cannot review your own timesheet.');
            abort_unless($row->status === 'submitted' && (int) $row->version === (int) $data['version'], 409, 'This timesheet is no longer awaiting this decision.');
            $query->update(['status' => $data['decision'], 'version' => $row->version + 1, 'reviewer_id' => $user->id, 'review_note' => $data['note'] ?? null, 'updated_at' => now()]);
            Audit::record('timesheet.'.$data['decision'], 'work', 'timesheet:'.$sheet);

            return $this->present($query->first());
        }, 3);
    }

    public function report(Request $request)
    {
        $user = $request->user();
        abort_unless($this->manage($user), 403);
        $week = $this->week($request)->toDateString();
        $totals = [];
        foreach ($this->sheets($user)->where('week_start', $week)->where('status', 'approved')->cursor() as $sheet) {
            foreach ($this->present($sheet)['entries'] as $entry) {
                $key = $entry['project'];
                $totals[$key] ??= ['project' => $key, 'name' => $entry['project_name'], 'billable' => 0, 'non_billable' => 0];
                $totals[$key][$entry['billable'] ? 'billable' : 'non_billable'] += $entry['minutes'];
            }
        }

        return array_values($totals);
    }
}
