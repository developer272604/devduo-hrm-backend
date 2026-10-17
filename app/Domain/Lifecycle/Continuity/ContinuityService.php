<?php

namespace App\Domain\Lifecycle\Continuity;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organizational Knowledge Continuity. People record the responsibilities only they really cover (with how critical each is, whether it is
 * documented, who the backup is and whether the backup is trained). Read against known exits (a pending or approved resignation, or a
 * notice-period last day), it shows which departures leave a critical gap, and can open the follow-up tasks. Everything is limited to
 * employees inside the viewer's data scope. It records readiness, it does not judge anyone.
 */
final class ContinuityService
{
    public const CRITICALITY = ['low', 'medium', 'high', 'critical'];

    private const WEIGHT = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    private const URGENT_DAYS = 60;

    public function __construct(private DataScope $scope, private TaskService $tasks) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function employeeInScope(User $u, string $uuid, string $permission): object
    {
        $q = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $u->tenant_id)->where('employees.uuid', $uuid)->whereNull('employees.deleted_at');
        $row = $this->scope->constrain($q, $u, $permission)->first(['employees.id', 'employees.uuid', 'employees.status', 'people.display_name as name', 'people.user_id']);

        return $row ?? abort(404);
    }

    public function present(Responsibility $r): array
    {
        $names = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', array_filter([$r->owner_employee_id, $r->backup_employee_id]))->pluck('people.display_name', 'employees.id');
        $uuids = DB::table('employees')->whereIn('id', array_filter([$r->owner_employee_id, $r->backup_employee_id]))->pluck('uuid', 'id');
        $gaps = $this->gaps($r);

        return ['uuid' => $r->uuid, 'title' => $r->title, 'description' => $r->description, 'criticality' => $r->criticality, 'status' => $r->status,
            'owner' => ['uuid' => $uuids[$r->owner_employee_id] ?? null, 'name' => $names[$r->owner_employee_id] ?? null],
            'backup' => $r->backup_employee_id ? ['uuid' => $uuids[$r->backup_employee_id] ?? null, 'name' => $names[$r->backup_employee_id] ?? null] : null,
            'documented' => $r->documented, 'documentation_ref' => $r->documentation_ref, 'backup_trained' => $r->backup_trained, 'last_reviewed_on' => $r->last_reviewed_on?->toDateString(), 'gaps' => $gaps, 'covered' => $gaps === []];
    }

    /** @return array<int,string> */
    private function gaps(Responsibility $r): array
    {
        $g = [];
        if (! $r->backup_employee_id) {
            $g[] = 'no_backup';
        } elseif (! $r->backup_trained) {
            $g[] = 'backup_not_trained';
        }
        if (! $r->documented) {
            $g[] = 'undocumented';
        }

        return $g;
    }

    public function list(User $u, array $f): array
    {
        $q = Responsibility::query()->join('employees', 'employees.id', '=', 'continuity_responsibilities.owner_employee_id')->select('continuity_responsibilities.*')
            ->when(($f['status'] ?? 'active') !== 'all', fn ($q) => $q->where('continuity_responsibilities.status', $f['status'] ?? 'active'))
            ->when($f['owner'] ?? null, fn ($q, $o) => $q->where('employees.uuid', $o));
        $this->scope->constrain($q, $u, 'lifecycle.continuity.view');
        $rows = $q->orderBy('continuity_responsibilities.id', 'desc')->limit(300)->get();

        return ['items' => $rows->map(fn ($r) => $this->present($r))->all()];
    }

    public function create(User $u, array $d): Responsibility
    {
        $owner = $this->employeeInScope($u, $d['owner_uuid'], 'lifecycle.continuity.manage');
        $this->assertValid($u, $d, $owner->id);
        $backup = isset($d['backup_uuid']) ? $this->employeeInScope($u, $d['backup_uuid'], 'lifecycle.continuity.view') : null;
        $r = Responsibility::create(['owner_employee_id' => $owner->id, 'title' => $d['title'], 'description' => $d['description'] ?? null, 'criticality' => $d['criticality'], 'documented' => (bool) ($d['documented'] ?? false),
            'documentation_ref' => $d['documentation_ref'] ?? null, 'backup_employee_id' => $backup?->id, 'backup_trained' => $backup ? (bool) ($d['backup_trained'] ?? false) : false, 'last_reviewed_on' => TenantClock::today(), 'status' => 'active', 'created_by' => $u->id]);
        Audit::record('continuity.create', 'lifecycle', 'responsibility:'.$r->uuid, ['criticality' => $d['criticality']]);

        return $r;
    }

    private function assertValid(User $u, array $d, int $ownerId): void
    {
        if (! empty($d['documented']) && trim((string) ($d['documentation_ref'] ?? '')) === '') {
            $this->fail('documentation_ref', 'Say where it is documented (a link or location) before marking it documented.');
        }
        if (! empty($d['backup_trained']) && empty($d['backup_uuid'])) {
            $this->fail('backup_uuid', 'Choose a backup before marking them trained.');
        }
        if (! empty($d['backup_uuid'])) {
            $backup = $this->employeeInScope($u, $d['backup_uuid'], 'lifecycle.continuity.view');
            if ($backup->id === $ownerId) {
                $this->fail('backup_uuid', 'The backup must be someone other than the owner.');
            }
            if (! in_array($backup->status, Employee::WORKING, true)) {
                $this->fail('backup_uuid', 'The backup must be a working employee.');
            }
        }
    }

    public function update(User $u, string $uuid, array $d): Responsibility
    {
        $r = Responsibility::where('uuid', $uuid)->firstOrFail();
        $this->scopedOwner($u, $r);
        $merged = ['documented' => $d['documented'] ?? $r->documented, 'documentation_ref' => array_key_exists('documentation_ref', $d) ? $d['documentation_ref'] : $r->documentation_ref,
            'backup_uuid' => array_key_exists('backup_uuid', $d) ? $d['backup_uuid'] : ($r->backup_employee_id ? DB::table('employees')->where('id', $r->backup_employee_id)->value('uuid') : null),
            'backup_trained' => $d['backup_trained'] ?? $r->backup_trained];
        if (array_key_exists('backup_uuid', $d) && $d['backup_uuid'] === null) {
            $merged['backup_trained'] = false; // no backup, so nobody can be trained
        }
        $this->assertValid($u, $merged, $r->owner_employee_id);
        $backupId = $merged['backup_uuid'] ? DB::table('employees')->where('uuid', $merged['backup_uuid'])->where('tenant_id', $u->tenant_id)->value('id') : null;
        $r->update(['title' => $d['title'] ?? $r->title, 'description' => array_key_exists('description', $d) ? $d['description'] : $r->description, 'criticality' => $d['criticality'] ?? $r->criticality, 'documented' => (bool) $merged['documented'],
            'documentation_ref' => $merged['documentation_ref'], 'backup_employee_id' => $backupId, 'backup_trained' => $backupId ? (bool) $merged['backup_trained'] : false, 'status' => $d['status'] ?? $r->status, 'last_reviewed_on' => TenantClock::today()]);
        if (($d['status'] ?? null) === 'retired') {
            foreach (['document', 'shadow', 'backup'] as $kind) {
                $this->tasks->completeBySource('continuity', 'responsibility:'.$r->uuid.':'.$kind);
            }
        }
        Audit::record('continuity.update', 'lifecycle', 'responsibility:'.$r->uuid, array_keys($d));

        return $r->refresh();
    }

    private function scopedOwner(User $u, Responsibility $r): void
    {
        $uuid = DB::table('employees')->where('id', $r->owner_employee_id)->value('uuid');
        $this->employeeInScope($u, $uuid, 'lifecycle.continuity.manage');
    }

    /** Known exit date per employee id: earliest of an active resignation's last day and a notice-period last working day, both today or later. */
    private function exitDates(User $u, array $employeeIds): array
    {
        $today = TenantClock::today();
        $out = [];
        foreach (DB::table('lifecycle_resignations')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $employeeIds)->whereIn('status', ['pending', 'approved'])->where('requested_last_day', '>=', $today)
            ->get(['employee_id', 'requested_last_day', 'status']) as $r) {
            $out[$r->employee_id] = ['date' => $r->requested_last_day, 'source' => 'resignation '.$r->status];
        }
        foreach (DB::table('employees')->where('tenant_id', $u->tenant_id)->whereIn('id', $employeeIds)->where('status', 'notice_period')->whereNotNull('separated_on')->where('separated_on', '>=', $today)->get(['id', 'separated_on']) as $e) {
            if (! isset($out[$e->id]) || $e->separated_on < $out[$e->id]['date']) {
                $out[$e->id] = ['date' => $e->separated_on, 'source' => 'notice period'];
            }
        }

        return $out;
    }

    public function risk(User $u): array
    {
        $q = Responsibility::query()->join('employees', 'employees.id', '=', 'continuity_responsibilities.owner_employee_id')->select('continuity_responsibilities.*')->where('continuity_responsibilities.status', 'active');
        $this->scope->constrain($q, $u, 'lifecycle.continuity.view');
        $rows = $q->get();
        $exits = $this->exitDates($u, $rows->pluck('owner_employee_id')->unique()->all());
        $today = CarbonImmutable::parse(TenantClock::today());
        $byOwner = [];
        foreach ($rows as $r) {
            $byOwner[$r->owner_employee_id][] = $r;
        }
        $owners = [];
        foreach ($byOwner as $ownerId => $items) {
            $exit = $exits[$ownerId] ?? null;
            $days = $exit ? $today->diffInDays(CarbonImmutable::parse($exit['date'])) : null;
            $atRisk = array_values(array_filter($items, fn ($r) => $this->gaps($r) !== []));
            $critical = array_values(array_filter($atRisk, fn ($r) => in_array($r->criticality, ['high', 'critical'], true)));
            if (! $atRisk) {
                continue;
            }
            $level = $exit && $critical && $days <= self::URGENT_DAYS ? 'urgent' : ($exit && $atRisk ? 'plan' : ($critical ? 'standing' : 'low'));
            usort($atRisk, fn ($a, $b) => self::WEIGHT[$b->criticality] <=> self::WEIGHT[$a->criticality]);
            $info = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $ownerId)->first(['employees.uuid', 'people.display_name as name']);
            $owners[] = ['owner' => ['uuid' => $info->uuid, 'name' => $info->name], 'level' => $level, 'exit' => $exit ? ['date' => $exit['date'], 'days_left' => $days, 'source' => $exit['source']] : null,
                'responsibilities' => count($items), 'uncovered' => count($atRisk), 'critical_uncovered' => count($critical), 'items' => array_map(fn ($r) => $this->present($r), $atRisk)];
        }
        $rank = ['urgent' => 0, 'plan' => 1, 'standing' => 2, 'low' => 3];
        usort($owners, fn ($a, $b) => [$rank[$a['level']], $a['exit']['days_left'] ?? 9999, -$a['critical_uncovered']] <=> [$rank[$b['level']], $b['exit']['days_left'] ?? 9999, -$b['critical_uncovered']]);

        return ['owners' => $owners, 'summary' => ['urgent' => count(array_filter($owners, fn ($o) => $o['level'] === 'urgent')), 'plan' => count(array_filter($owners, fn ($o) => $o['level'] === 'plan')),
            'standing' => count(array_filter($owners, fn ($o) => $o['level'] === 'standing')), 'responsibilities_recorded' => $rows->count()],
            'definitions' => ['urgent' => 'Leaving within '.self::URGENT_DAYS.' days and a high or critical responsibility has no trained backup or no documentation.', 'plan' => 'A known exit date and at least one uncovered responsibility.',
                'standing' => 'No exit known, but a high or critical responsibility has no trained backup or no documentation.', 'low' => 'Only low or medium responsibilities are uncovered.'],
            'limitations' => 'This reflects what has been recorded. A responsibility nobody recorded does not appear. Exit dates come from resignations and notice periods already in the system.'];
    }

    /** Opens follow-up tasks for one person's uncovered responsibilities (never duplicated): document it, train the backup, or name a backup. */
    public function handover(User $u, string $employeeUuid): array
    {
        $owner = $this->employeeInScope($u, $employeeUuid, 'lifecycle.continuity.manage');
        $created = 0;
        $skipped = 0;
        foreach (Responsibility::where('owner_employee_id', $owner->id)->where('status', 'active')->get() as $r) {
            $jobs = [];
            if (! $r->documented && $owner->user_id) {
                $jobs[] = [$owner->user_id, 'Document how you cover: '.$r->title, 'document'];
            }
            if ($r->backup_employee_id && ! $r->backup_trained) {
                $backupUser = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $r->backup_employee_id)->value('people.user_id');
                if ($backupUser) {
                    $jobs[] = [$backupUser, 'Shadow '.$owner->name.' on: '.$r->title, 'shadow'];
                }
            }
            if (! $r->backup_employee_id) {
                $jobs[] = [$u->id, 'Name a backup for '.$owner->name.': '.$r->title, 'backup'];
            }
            foreach ($jobs as [$assignee, $title, $kind]) {
                $task = $this->tasks->createOnce(['assignee_id' => $assignee, 'title' => $title, 'description' => 'Critical: '.$r->criticality.'. Part of the knowledge handover plan.', 'source_module' => 'continuity',
                    'source_entity' => 'responsibility:'.$r->uuid.':'.$kind, 'priority' => in_array($r->criticality, ['high', 'critical'], true) ? 'high' : 'normal', 'action' => '/lifecycle/continuity', 'metadata' => ['responsibility_uuid' => $r->uuid]]);
                $task ? $created++ : $skipped++;
            }
        }
        Audit::record('continuity.handover', 'lifecycle', 'employee:'.$owner->uuid, ['created' => $created, 'already_open' => $skipped]);

        return ['created' => $created, 'already_open' => $skipped];
    }
}
