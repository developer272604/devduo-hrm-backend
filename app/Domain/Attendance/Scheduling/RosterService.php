<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\Roster;
use App\Domain\Attendance\Models\RosterEntry;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Roster model: per (roster, employee, date) at most one DRAFT row and one PUBLISHED row.
 *  - editing an unpublished cell updates its draft; editing a published cell creates a NEW draft pointing at the published row
 *    (previous_entry_id, change_reason, changed_by, changed_at)
 *  - publish: drafts become published, the replaced published rows become 'superseded'. Published history is never deleted or overwritten.
 */
final class RosterService
{
    public function __construct(private ConflictDetector $conflicts, private DomainEvents $events, private DatabaseScheduleResolver $resolver) {}

    // ---- rosters ------------------------------------------------------------------------------------------------------------

    public function create(array $in, User $actor): Roster
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Give the roster a name, for example "Chennai support - November".';
        }
        $from = $in['start_date'] ?? null;
        $to = $in['end_date'] ?? null;
        if (! $this->isDate($from)) {
            $errors['start_date'] = 'Enter the first date of the roster (YYYY-MM-DD).';
        }
        if (! $this->isDate($to) || ($from && $to < $from)) {
            $errors['end_date'] = 'The end date must be on or after the start date.';
        } elseif ($from && CarbonImmutable::parse($from)->diffInDays($to) > 92) {
            $errors['end_date'] = 'A roster can span at most about three months. Create several rosters for longer plans.';
        }
        $scopeType = $in['scope_type'] ?? 'custom';
        if (! in_array($scopeType, ['custom', 'department', 'team', 'location', 'business_unit', 'employee_group'], true)) {
            $errors['scope_type'] = 'Scope must be custom, department, team, location, business_unit or employee_group.';
        } elseif ($scopeType !== 'custom' && ! TargetMembers::exists($scopeType, (string) ($in['scope_id'] ?? ''))) {
            $errors['scope_id'] = 'We could not find that '.str_replace('_', ' ', $scopeType).'.';
        }
        if ($errors) {
            Fail::fields($errors);
        }

        return DB::transaction(function () use ($name, $from, $to, $scopeType, $in, $actor) {
            $r = Roster::create(['name' => $name, 'scope_type' => $scopeType, 'scope_id' => $scopeType === 'custom' ? null : (string) $in['scope_id'], 'start_date' => $from, 'end_date' => $to, 'status' => 'draft', 'created_by' => $actor->id]);
            Audit::record('created', 'attendance', 'roster:'.$r->uuid, ['name' => $name, 'from' => $from, 'to' => $to]);

            return $r;
        });
    }

    public function present(Roster $r): array
    {
        $counts = RosterEntry::query()->where('roster_id', $r->id)->whereIn('status', ['draft', 'published'])->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return ['uuid' => $r->uuid, 'name' => $r->name, 'scope_type' => $r->scope_type, 'scope_id' => $r->scope_id, 'start_date' => $r->start_date, 'end_date' => $r->end_date, 'status' => $r->status,
            'publication_version' => (int) $r->publication_version, 'published_at' => $r->published_at?->toIso8601String(), 'draft_entries' => (int) ($counts['draft'] ?? 0), 'published_entries' => (int) ($counts['published'] ?? 0),
            'has_unpublished_changes' => ($counts['draft'] ?? 0) > 0];
    }

    // ---- editing --------------------------------------------------------------------------------------------------------------

    /** Sets one cell (assign/change/off/clear). Employee must be in the editor's data scope. */
    public function setEntry(Roster $roster, Employee $employee, string $date, string $type, ?Shift $shift, ?string $reason, User $actor, ?int $expectedVersion = null): RosterEntry
    {
        return DB::transaction(function () use ($roster, $employee, $date, $type, $shift, $reason, $actor, $expectedVersion) {
            $roster = Roster::query()->whereKey($roster->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $expectedVersion !== (int) $roster->publication_version) {
                Fail::business('roster_changed', 'This roster was published or changed by someone else while you were editing. Reload it and try again.', 409);
            }

            return $this->writeCell($roster, $employee, $date, $type, $shift, $reason, $actor);
        });
    }

    private function writeCell(Roster $roster, Employee $employee, string $date, string $type, ?Shift $shift, ?string $reason, User $actor): RosterEntry
    {
        if ($date < $roster->start_date || $date > $roster->end_date) {
            Fail::field('date', "{$date} is outside this roster ({$roster->start_date} to {$roster->end_date}).");
        }
        if (! in_array($type, ['shift', 'off', 'cleared'], true)) {
            Fail::field('entry_type', 'Entry type must be shift, off or cleared.');
        }
        if ($type === 'shift' && (! $shift || ! $shift->active)) {
            Fail::field('shift_uuid', $shift ? "Shift {$shift->code} is inactive." : 'Choose a shift.');
        }
        SchedulingEffects::assertUnlocked($employee->id, $date);
        $published = RosterEntry::query()->where('roster_id', $roster->id)->where('employee_id', $employee->id)->where('work_date', $date)->where('status', 'published')->first();
        $draft = RosterEntry::query()->where('roster_id', $roster->id)->where('employee_id', $employee->id)->where('work_date', $date)->where('status', 'draft')->first();
        $reason = $reason !== null ? trim($reason) : null;
        if ($published && ! $draft && ($reason === null || $reason === '')) {
            Fail::field('reason', 'This day is already published. Give a reason for the change; employees will see it.');
        }
        $values = ['entry_type' => $type, 'shift_id' => $type === 'shift' ? $shift->id : null, 'change_reason' => $reason ?: ($draft->change_reason ?? null), 'changed_by' => $actor->id, 'changed_at' => now()];
        if ($draft) {
            $draft->update($values);
            $entry = $draft;
        } else {
            $entry = RosterEntry::create($values + ['roster_id' => $roster->id, 'employee_id' => $employee->id, 'work_date' => $date, 'status' => 'draft', 'previous_entry_id' => $published?->id]);
        }
        // A draft identical to what is published is no change at all.
        if ($published && $published->entry_type === $entry->entry_type && (int) $published->shift_id === (int) $entry->shift_id) {
            $entry->delete();
            $entry->exists = false;
        }
        Audit::record('entry_set', 'attendance', 'roster:'.$roster->uuid, ['employee' => $employee->uuid, 'date' => $date, 'type' => $type, 'shift' => $shift?->code, 'changed_published' => (bool) $published]);

        return $entry;
    }

    /** Discards the draft of a cell (back to what is published). */
    public function discardDraft(Roster $roster, Employee $employee, string $date): void
    {
        RosterEntry::query()->where('roster_id', $roster->id)->where('employee_id', $employee->id)->where('work_date', $date)->where('status', 'draft')->delete();
    }

    /**
     * Bulk assign with conflict preview. $employees are already authorized by the caller.
     *
     * @param  array<int,Employee>  $employees
     * @return array{preview:bool,cells:int,conflicts:array,locked:array,applied:int}
     */
    public function bulkAssign(Roster $roster, array $employees, string $from, string $to, ?array $weekdays, string $type, ?Shift $shift, ?string $reason, User $actor, bool $preview, bool $acknowledge): array
    {
        if ($from < $roster->start_date || $to > $roster->end_date || $to < $from) {
            Fail::field('from', "Dates must be inside the roster ({$roster->start_date} to {$roster->end_date}).");
        }
        $dates = array_values(array_filter(DatabaseScheduleResolver::dates($from, $to), fn ($d) => ! $weekdays || in_array((int) CarbonImmutable::parse($d)->isoWeekday(), $weekdays, true)));
        $overlay = [];
        $locked = [];
        foreach ($employees as $e) {
            foreach ($dates as $d) {
                $overlay[$e->id][$d] = ['entry_type' => $type, 'shift_id' => $shift?->id];
                if (SchedulingEffects::isLocked($e->id, $d)) {
                    $locked[] = ['employee_id' => $e->id, 'date' => $d];
                }
            }
        }
        $conflicts = $type === 'cleared' ? [] : $this->conflicts->detect(array_map(fn ($e) => $e->id, $employees), $from, $to, $this->mergeDrafts($roster, $overlay));
        $result = ['preview' => $preview, 'cells' => count($employees) * count($dates), 'conflicts' => $conflicts, 'locked' => $locked, 'applied' => 0];
        if ($preview) {
            return $result;
        }
        if ($locked) {
            SchedulingEffects::assertUnlocked($locked[0]['employee_id'], $locked[0]['date']);
        }
        if (ConflictDetector::hasBlocking($conflicts)) {
            Fail::business('roster_conflicts', 'Some assignments break configured limits that this workspace blocks. Review the conflicts.', 422, ['conflicts' => $conflicts]);
        }
        if ($conflicts && ! $acknowledge) {
            Fail::business('roster_warnings', count($conflicts).' warning(s) were found. Review them and resend with acknowledge_warnings=true to apply anyway.', 409, ['conflicts' => $conflicts]);
        }
        DB::transaction(function () use ($roster, $employees, $dates, $type, $shift, $reason, $actor, &$result) {
            $roster = Roster::query()->whereKey($roster->id)->lockForUpdate()->firstOrFail();
            foreach ($employees as $e) {
                foreach ($dates as $d) {
                    $this->writeCell($roster, $e, $d, $type, $shift, $reason, $actor);
                    $result['applied']++;
                }
            }
            Audit::record('bulk_assigned', 'attendance', 'roster:'.$roster->uuid, ['employees' => count($employees), 'dates' => count($dates), 'type' => $type, 'shift' => $shift?->code]);
        });

        return $result;
    }

    /** Copies the effective schedule of a source week (or any N days) into drafts of the target dates. @param array<int,Employee> $employees */
    public function copy(Roster $roster, array $employees, string $sourceFrom, string $targetFrom, int $days, User $actor): array
    {
        if ($days < 1 || $days > 31) {
            Fail::field('days', 'Copy between 1 and 31 days at a time.');
        }
        $targetTo = CarbonImmutable::parse($targetFrom)->addDays($days - 1)->toDateString();
        if ($targetFrom < $roster->start_date || $targetTo > $roster->end_date) {
            Fail::field('target_from', "The target dates must be inside the roster ({$roster->start_date} to {$roster->end_date}).");
        }
        $sourceTo = CarbonImmutable::parse($sourceFrom)->addDays($days - 1)->toDateString();
        $ids = array_map(fn ($e) => $e->id, $employees);
        $decider = ScheduleDecider::load($ids, $sourceFrom, $sourceTo);
        $drafts = RosterEntry::query()->where('status', 'draft')->whereIn('employee_id', $ids)->whereBetween('work_date', [$sourceFrom, $sourceTo])->get()->groupBy('employee_id');
        $copied = 0;
        $skipped = 0;
        DB::transaction(function () use ($roster, $employees, $days, $sourceFrom, $targetFrom, $actor, $decider, $drafts, &$copied, &$skipped) {
            $roster = Roster::query()->whereKey($roster->id)->lockForUpdate()->firstOrFail();
            foreach ($employees as $e) {
                for ($i = 0; $i < $days; $i++) {
                    $src = CarbonImmutable::parse($sourceFrom)->addDays($i)->toDateString();
                    $dst = CarbonImmutable::parse($targetFrom)->addDays($i)->toDateString();
                    $draft = ($drafts[$e->id] ?? collect())->firstWhere('work_date', $src);
                    $type = null;
                    $shift = null;
                    if ($draft) {
                        $type = $draft->entry_type;
                        $shift = $draft->shift_id ? Shift::query()->find($draft->shift_id) : null;
                    } else {
                        $d = $decider->decide($e->id, $src);
                        if ($d['kind'] === 'working' && $d['shift']) {
                            $type = 'shift';
                            $shift = Shift::query()->find($d['shift']['id']);
                        } elseif ($d['kind'] === 'weekly_off' && $d['source'] === 'roster') {
                            $type = 'off';
                        }
                    }
                    if (! $type || ($type === 'shift' && ! $shift?->active) || SchedulingEffects::isLocked($e->id, $dst)) {
                        $skipped++;

                        continue;
                    }
                    $published = RosterEntry::query()->where('roster_id', $roster->id)->where('employee_id', $e->id)->where('work_date', $dst)->where('status', 'published')->exists();
                    $this->writeCell($roster, $e, $dst, $type, $shift, $published ? 'Copied from '.$src : null, $actor);
                    $copied++;
                }
            }
            Audit::record('copied', 'attendance', 'roster:'.$roster->uuid, ['from' => $sourceFrom, 'to' => $targetFrom, 'days' => $days, 'copied' => $copied]);
        });

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    // ---- validate / publish --------------------------------------------------------------------------------------------------

    /** Conflicts that would exist if the roster's drafts were published. */
    public function validate(Roster $roster): array
    {
        $entries = RosterEntry::query()->where('roster_id', $roster->id)->whereIn('status', ['draft', 'published'])->orderByRaw("case status when 'published' then 0 else 1 end")->get();
        $overlay = [];
        $duplicates = [];
        foreach ($entries as $e) {
            $overlay[$e->employee_id][$e->work_date] = ['entry_type' => $e->entry_type, 'shift_id' => $e->shift_id, 'previous_entry_id' => $e->previous_entry_id, 'id' => $e->id];
        }
        $ids = array_keys($overlay);
        $conflicts = $this->conflicts->detect($ids, $roster->start_date, $roster->end_date, $overlay);
        // Duplicate assignments: the same employee-day drafted in two different rosters with different outcomes.
        $dup = RosterEntry::query()->where('status', 'draft')->where('roster_id', '!=', $roster->id)->whereIn('employee_id', $ids ?: [0])->whereBetween('work_date', [$roster->start_date, $roster->end_date])->get();
        foreach ($dup as $o) {
            $mine = $overlay[$o->employee_id][$o->work_date] ?? null;
            if ($mine) {
                $duplicates[] = ['employee_id' => $o->employee_id, 'date' => $o->work_date, 'code' => 'duplicate_assignment', 'rule' => 'Duplicate assignments', 'layer' => 'global', 'severity' => 'action_required',
                    'level' => 'flag', 'message' => 'Another unpublished roster also assigns this employee on '.$o->work_date.'. Only one roster should schedule a person per day.'];
            }
        }
        $all = array_merge($conflicts, $duplicates);
        $names = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', app(TenantContext::class)->id)->whereIn('employees.id', $ids ?: [0])->pluck('people.display_name', 'employees.id');
        foreach ($all as &$c) {
            $c['employee'] = $names[$c['employee_id']] ?? null;
        }
        unset($c);

        return $all;
    }

    public function publish(Roster $roster, User $actor, bool $acknowledge, ?int $expectedVersion = null): array
    {
        $conflicts = $this->validate($roster);
        if (ConflictDetector::hasBlocking($conflicts)) {
            Fail::business('roster_conflicts', 'This roster has conflicts that this workspace does not allow. Resolve them before publishing.', 422, ['conflicts' => $conflicts]);
        }
        if ($conflicts && ! $acknowledge) {
            Fail::business('roster_warnings', count($conflicts).' warning(s) were found. Review them and resend with acknowledge_warnings=true to publish anyway.', 409, ['conflicts' => $conflicts]);
        }

        return DB::transaction(function () use ($roster, $actor, $expectedVersion, $conflicts) {
            $locked = Roster::query()->whereKey($roster->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $expectedVersion !== (int) $locked->publication_version) {
                Fail::business('roster_changed', 'This roster was published by someone else in the meantime. Reload it first.', 409);
            }
            $drafts = RosterEntry::query()->where('roster_id', $locked->id)->where('status', 'draft')->get();
            if ($drafts->isEmpty()) {
                Fail::business('nothing_to_publish', 'There are no unpublished changes in this roster.', 422);
            }
            foreach ($drafts as $d) {
                SchedulingEffects::assertUnlocked($d->employee_id, $d->work_date);
            }
            $version = (int) $locked->publication_version + 1;
            $now = now();
            $summary = $this->applyDrafts($locked, $drafts, $version, $now, $actor);
            $locked->update(['status' => 'published', 'publication_version' => $version, 'published_at' => $now, 'published_by' => $actor->id]);
            Audit::record('published', 'attendance', 'roster:'.$locked->uuid, ['version' => $version, 'entries' => $drafts->count(), 'warnings' => count($conflicts)]);
            $this->events->record('RosterPublished', ['summary' => "Roster {$locked->name} published (version {$version})", 'subject' => ['roster', $locked->id], 'payload' => ['roster' => $locked->uuid, 'version' => $version, 'entries' => $drafts->count(), 'employees' => count($summary)]]);
            $this->announce($locked, $summary, $actor);
            $by = $drafts->groupBy('employee_id')->map(fn ($g) => $g->pluck('work_date')->all());
            foreach ($by as $emp => $dates) {
                SchedulingEffects::recalculate([$emp], min($dates), max($dates), $actor->id, 'roster:'.$locked->id.':'.$version);
            }

            return ['version' => $version, 'entries_published' => $drafts->count(), 'employees_notified' => count($summary), 'warnings' => $conflicts];
        });
    }

    /** Turns drafts into published rows, superseding previous published rows. @return array<int,array{changed:array,assigned:array}> per-employee summary */
    public function applyDrafts(Roster $roster, $drafts, int $version, $now, User $actor): array
    {
        $summary = [];
        foreach ($drafts as $d) {
            $prev = RosterEntry::query()->where('employee_id', $d->employee_id)->where('work_date', $d->work_date)->where('status', 'published')->where('id', '!=', $d->id)->orderByDesc('id')->get();
            $previous = $prev->first();
            foreach ($prev as $p) {
                $p->update(['status' => 'superseded', 'superseded_at' => $now]);
            }
            $d->update(['status' => 'published', 'publication_version' => $version, 'published_at' => $now, 'previous_entry_id' => $d->previous_entry_id ?? $previous?->id]);
            $summary[$d->employee_id][$previous ? 'changed' : 'assigned'][] = ['date' => $d->work_date, 'entry' => $d, 'previous' => $previous];
        }

        return $summary;
    }

    /** One event + one notification per employee per publication. */
    public function announce(Roster $roster, array $summary, User $actor): void
    {
        $codes = Shift::query()->pluck('code', 'id');
        $users = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $roster->tenant_id)->whereIn('employees.id', array_keys($summary) ?: [0])->pluck('people.user_id', 'employees.id');
        foreach ($summary as $employeeId => $groups) {
            foreach (['assigned' => 'ShiftAssigned', 'changed' => 'ShiftChanged'] as $key => $type) {
                if (empty($groups[$key])) {
                    continue;
                }
                $dates = array_column($groups[$key], 'date');
                $detail = array_map(fn ($g) => ['date' => $g['date'], 'shift' => $g['entry']->entry_type === 'shift' ? ($codes[$g['entry']->shift_id] ?? null) : $g['entry']->entry_type,
                    'previous' => $g['previous'] ? ($g['previous']->entry_type === 'shift' ? ($codes[$g['previous']->shift_id] ?? null) : $g['previous']->entry_type) : null, 'reason' => $g['entry']->change_reason], $groups[$key]);
                $this->events->record($type, ['employee' => $employeeId, 'summary' => ($key === 'assigned' ? 'Shift assigned' : 'Shift changed').' for '.count($dates).' day(s) from '.min($dates).' to '.max($dates), 'effective_date' => min($dates),
                    'payload' => ['roster' => $roster->uuid, 'days' => $detail]]);
            }
            $userId = $users[$employeeId] ?? null;
            if ($userId && (bool) \App\Domain\Attendance\Support\AttendanceSettings::get('notifications.roster_published', true)) {
                $n = count($groups['assigned'] ?? []);
                $c = count($groups['changed'] ?? []);
                $all = array_merge(array_column($groups['assigned'] ?? [], 'date'), array_column($groups['changed'] ?? [], 'date'));
                app(NotificationService::class)->send((int) $userId, $c ? 'Your schedule changed' : 'Your schedule is published',
                    trim(($n ? "$n new shift(s)" : '').($n && $c ? ' and ' : '').($c ? "$c changed shift(s)" : '').' between '.min($all).' and '.max($all).'. Open your schedule for details.'), 'attendance', '/attendance?tab=schedule');
            }
        }
    }

    /** Un-publishes: current published rows become superseded and are restored as drafts, so nothing is lost and the roster can be republished. */
    public function unpublish(Roster $roster, User $actor, ?string $reason): void
    {
        DB::transaction(function () use ($roster, $actor, $reason) {
            $locked = Roster::query()->whereKey($roster->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'published') {
                Fail::business('not_published', 'This roster is not published.', 422);
            }
            $published = RosterEntry::query()->where('roster_id', $locked->id)->where('status', 'published')->get();
            foreach ($published as $p) {
                SchedulingEffects::assertUnlocked($p->employee_id, $p->work_date);
            }
            $hasDraft = RosterEntry::query()->where('roster_id', $locked->id)->where('status', 'draft')->get()->mapWithKeys(fn ($d) => [$d->employee_id.'|'.$d->work_date => true]);
            foreach ($published as $p) {
                $p->update(['status' => 'superseded', 'superseded_at' => now()]);
                if (! isset($hasDraft[$p->employee_id.'|'.$p->work_date])) {
                    RosterEntry::create(['roster_id' => $locked->id, 'employee_id' => $p->employee_id, 'work_date' => $p->work_date, 'entry_type' => $p->entry_type, 'shift_id' => $p->shift_id,
                        'status' => 'draft', 'previous_entry_id' => $p->id, 'change_reason' => 'Unpublished: '.($reason ?: 'no reason given'), 'changed_by' => $actor->id, 'changed_at' => now()]);
                }
            }
            $locked->update(['status' => 'draft']);
            Audit::record('unpublished', 'attendance', 'roster:'.$locked->uuid, ['reason' => $reason, 'entries' => $published->count()]);
            $this->events->record('RosterUnpublished', ['summary' => "Roster {$locked->name} unpublished", 'subject' => ['roster', $locked->id], 'payload' => ['reason' => $reason]]);
            foreach ($published->groupBy('employee_id') as $emp => $rows) {
                SchedulingEffects::recalculate([$emp], $rows->min('work_date'), $rows->max('work_date'), $actor->id, 'roster-unpublish:'.$locked->id);
            }
        });
    }

    // ---- reading --------------------------------------------------------------------------------------------------------------

    /** Per-entry history for one cell: previous shift / new shift / changed by / at / reason / effective date. */
    public function history(Roster $roster, Employee $employee, string $date): array
    {
        $codes = Shift::query()->pluck('code', 'id');
        $users = DB::table('users')->where('tenant_id', $roster->tenant_id)->pluck('name', 'id');
        $rows = RosterEntry::query()->where('employee_id', $employee->id)->where('work_date', $date)->orderBy('id')->get();
        $label = fn ($e) => $e ? ($e->entry_type === 'shift' ? ($codes[$e->shift_id] ?? '?') : $e->entry_type) : null;
        $byId = $rows->keyBy('id');

        return $rows->map(fn ($e) => ['id' => $e->id, 'roster_id' => $e->roster_id, 'status' => $e->status, 'previous_shift' => $label($e->previous_entry_id ? $byId[$e->previous_entry_id] ?? null : null),
            'new_shift' => $label($e), 'changed_by' => $e->changed_by ? ($users[$e->changed_by] ?? null) : null, 'changed_at' => $e->changed_at?->toIso8601String(), 'reason' => $e->change_reason,
            'effective_date' => $e->work_date, 'publication_version' => $e->publication_version, 'published_at' => $e->published_at?->toIso8601String()])->values()->all();
    }

    /** Entries that changed after a publication (new drafts replacing published rows). */
    public function changedSince(Roster $roster): array
    {
        $codes = Shift::query()->pluck('code', 'id');
        $rows = RosterEntry::query()->where('roster_id', $roster->id)->where('status', 'draft')->whereNotNull('previous_entry_id')->orderBy('work_date')->get();
        $prev = RosterEntry::query()->whereIn('id', $rows->pluck('previous_entry_id'))->get()->keyBy('id');
        $names = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', $rows->pluck('employee_id'))->pluck('people.display_name', 'employees.id');
        $label = fn ($e) => $e->entry_type === 'shift' ? ($codes[$e->shift_id] ?? '?') : $e->entry_type;

        return $rows->map(fn ($e) => ['employee_id' => $e->employee_id, 'employee' => $names[$e->employee_id] ?? null, 'date' => $e->work_date, 'previous_shift' => $label($prev[$e->previous_entry_id]), 'new_shift' => $label($e), 'reason' => $e->change_reason,
            'changed_at' => $e->changed_at?->toIso8601String()])->values()->all();
    }

    /**
     * Grid for a page of employees. Bounded queries: employees (1-2), people (1), decider batch (fixed), drafts (1).
     *
     * @param  array<int,int>  $employeeIds
     */
    public function grid(Roster $roster, array $employeeIds, string $from, string $to): array
    {
        $decider = ScheduleDecider::load($employeeIds, $from, $to);
        $drafts = RosterEntry::query()->where('status', 'draft')->whereIn('employee_id', $employeeIds ?: [0])->whereBetween('work_date', [$from, $to])->orderBy('id')->get()->groupBy('employee_id');
        $codes = Shift::query()->get(['id', 'code', 'name', 'color_token', 'start_time', 'end_time'])->keyBy('id');
        $dates = DatabaseScheduleResolver::dates($from, $to);
        $rows = [];
        foreach ($employeeIds as $id) {
            $cells = [];
            $mine = ($drafts[$id] ?? collect())->keyBy('work_date');
            foreach ($dates as $date) {
                $d = $decider->decide($id, $date);
                $draft = $mine[$date] ?? null;
                $shift = $d['shift'];
                $changed = $d['roster_entry'] && ! empty($d['roster_entry']['previous_entry_id']);
                $cells[$date] = ['kind' => $d['kind'], 'source' => $d['source'], 'shift' => $shift ? ['code' => $shift['code'], 'name' => $shift['name'], 'start' => substr($shift['start_time'], 0, 5), 'end' => substr($shift['end_time'], 0, 5), 'color' => $shift['color_token']] : null,
                    'holiday' => $d['holiday']['name'] ?? null,
                    'state' => $draft ? 'draft' : ($changed ? 'changed' : ($d['source'] === 'roster' ? 'published' : 'derived')),
                    'draft' => $draft ? ['type' => $draft->entry_type, 'shift' => $draft->shift_id ? ($codes[$draft->shift_id]->code ?? null) : null, 'reason' => $draft->change_reason] : null];
            }
            $rows[$id] = $cells;
        }

        return $rows;
    }

    /** Employee ids a roster is about (scope or entries), restricted to the viewer's data scope, paginated. */
    public function scopedEmployees(Roster $roster, User $viewer, string $from, array $extraUuids, int $page, int $perPage, ?string $search = null): array
    {
        $q = Employee::query()->join('people', 'people.id', '=', 'employees.person_id')->select('employees.*', 'people.display_name');
        app(DataScope::class)->constrain($q, $viewer, 'attendance.roster.manage', 'employees.id');
        $scopeId = $roster->scope_id;
        $q->where(function ($w) use ($roster, $scopeId, $from, $extraUuids) {
            $w->whereIn('employees.id', RosterEntry::query()->where('roster_id', $roster->id)->select('employee_id'));
            if ($extraUuids) {
                $w->orWhereIn('employees.uuid', $extraUuids);
            }
            if ($roster->scope_type !== 'custom') {
                $w->orWhereIn('employees.id', $roster->scope_type === 'employee_group'
                    ? DB::table('employee_group_members')->where('group_id', $scopeId)->select('employee_id')
                    : DB::table('employee_assignments')->where($roster->scope_type.'_id', $scopeId)->where('effective_from', '<=', $roster->end_date)
                        ->where(fn ($x) => $x->whereNull('effective_to')->orWhere('effective_to', '>=', $roster->start_date))->select('employee_id'));
            }
        })->where('employees.joined_on', '<=', $roster->end_date)->where(fn ($x) => $x->whereNull('employees.separated_on')->orWhere('employees.separated_on', '>=', $roster->start_date));
        if ($search) {
            $q->where('people.display_name', 'like', '%'.$search.'%');
        }
        $total = (clone $q)->count();
        $rows = $q->orderBy('people.display_name')->orderBy('employees.id')->forPage($page, $perPage)->get();

        return ['employees' => $rows, 'total' => $total];
    }

    /** Draft entries of this roster merged over a proposed overlay (so validation sees the whole plan). */
    private function mergeDrafts(Roster $roster, array $overlay): array
    {
        $ids = array_keys($overlay);
        $rows = RosterEntry::query()->where('roster_id', $roster->id)->whereIn('status', ['draft', 'published'])->whereIn('employee_id', $ids ?: [0])->orderByRaw("case status when 'published' then 0 else 1 end")->get();
        $merged = [];
        foreach ($rows as $e) {
            $merged[$e->employee_id][$e->work_date] = ['entry_type' => $e->entry_type, 'shift_id' => $e->shift_id];
        }
        foreach ($overlay as $emp => $dates) {
            foreach ($dates as $d => $entry) {
                $merged[$emp][$d] = $entry;
            }
        }

        return $merged;
    }

    /**
     * Writes a PUBLISHED entry directly (used by approved shift swaps) with history, in the caller's transaction.
     */
    public function writePublished(int $employeeId, string $date, string $type, ?int $shiftId, string $reason, User $actor): RosterEntry
    {
        $month = substr($date, 0, 7);
        $name = 'Approved changes '.$month;
        $roster = Roster::query()->where('name', $name)->where('scope_type', 'custom')->lockForUpdate()->first()
            ?? Roster::create(['name' => $name, 'scope_type' => 'custom', 'start_date' => $month.'-01', 'end_date' => CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString(), 'status' => 'published',
                'publication_version' => 0, 'published_at' => now(), 'published_by' => $actor->id, 'created_by' => $actor->id]);
        $version = (int) $roster->publication_version + 1;
        $roster->update(['publication_version' => $version, 'published_at' => now(), 'status' => 'published']);
        $previous = RosterEntry::query()->where('employee_id', $employeeId)->where('work_date', $date)->where('status', 'published')->orderByDesc('id')->first();
        $previous?->update(['status' => 'superseded', 'superseded_at' => now()]);

        return RosterEntry::create(['roster_id' => $roster->id, 'employee_id' => $employeeId, 'work_date' => $date, 'entry_type' => $type, 'shift_id' => $shiftId, 'status' => 'published', 'publication_version' => $version,
            'previous_entry_id' => $previous?->id, 'change_reason' => $reason, 'changed_by' => $actor->id, 'changed_at' => now(), 'published_at' => now()]);
    }

    private function isDate(mixed $v): bool
    {
        return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
