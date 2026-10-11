<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Scheduling\HolidayService;
use App\Domain\Attendance\Scheduling\RotationService;
use App\Domain\Attendance\Scheduling\ScheduleAssignmentService;
use App\Domain\Attendance\Scheduling\ShiftService;
use App\Domain\Attendance\Scheduling\WorkWeekService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class SchedulingResolverTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
    }

    protected function shift(string $code, string $start = '09:00', string $end = '18:00', array $extra = []): Shift
    {
        $segments = $extra['segments'] ?? [['kind' => 'work', 'start' => $start, 'end' => $end]];
        unset($extra['segments']);

        return app(ShiftService::class)->create($extra + ['name' => $code.' shift', 'code' => $code, 'timezone' => 'Asia/Kolkata', 'start_time' => $start, 'end_time' => $end, 'segments' => $segments]);
    }

    protected function week(string $code = 'MF', string $preset = 'mon_fri', string $from = '2026-01-01')
    {
        return app(WorkWeekService::class)->create(['name' => $code, 'code' => $code, 'preset' => $preset, 'effective_from' => $from], $this->admin);
    }

    protected function assign(string $kind, array $data)
    {
        return app(ScheduleAssignmentService::class)->create($kind, $data + ['effective_from' => '2026-01-01'], $this->admin);
    }

    protected function resolve(Employee $e, string $date)
    {
        return app(ScheduleResolver::class)->resolve($e->id, $date);
    }

    public function test_specificity_priority_override_and_determinism(): void
    {
        $e = $this->hire('Asha Rao');
        $tenantShift = $this->shift('GEN');
        $deptShift = $this->shift('ENG', '10:00', '19:00');
        $empShift = $this->shift('EMP', '07:00', '16:00');
        $this->week();
        $this->assign('work_week', ['target_type' => 'tenant', 'work_week_uuid' => \App\Domain\Attendance\Models\WorkWeek::first()->uuid]);
        $this->assign('shift', ['target_type' => 'tenant', 'shift_uuid' => $tenantShift->uuid]);
        $this->assign('shift', ['target_type' => 'department', 'target_id' => $this->org['department'][0], 'shift_uuid' => $deptShift->uuid]);

        $d = $this->resolve($e, '2026-10-12'); // Monday
        $this->assertSame('ENG', $d->shiftCode);
        $this->assertSame('working', $d->kind);
        $this->assertStringContainsString('department', implode(' ', $d->notes));

        $this->assign('shift', ['target_type' => 'employee', 'target_id' => $e->id, 'shift_uuid' => $empShift->uuid]);
        $this->assertSame('EMP', $this->resolve($e, '2026-10-12')->shiftCode);

        // an override at lower specificity does not beat a more specific assignment, but beats equal-specificity priority
        $this->assign('shift', ['target_type' => 'department', 'target_id' => $this->org['department'][0], 'shift_uuid' => $tenantShift->uuid, 'priority' => 5, 'is_override' => true, 'effective_from' => '2026-11-01']);
        $this->assertSame('EMP', $this->resolve($e, '2026-11-02')->shiftCode);
    }

    public function test_ambiguous_assignments_are_rejected_at_write_time(): void
    {
        $a = $this->shift('A');
        $b = $this->shift('B', '10:00', '19:00');
        $this->assign('shift', ['target_type' => 'department', 'target_id' => $this->org['department'][0], 'shift_uuid' => $a->uuid]);
        try {
            $this->assign('shift', ['target_type' => 'department', 'target_id' => $this->org['department'][0], 'shift_uuid' => $b->uuid, 'effective_from' => '2026-06-01']);
            $this->fail('expected ambiguity rejection');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame('ambiguous_assignment', $e->getResponse()->getData(true)['code']);
        }
        // different priority is fine
        $this->assign('shift', ['target_type' => 'department', 'target_id' => $this->org['department'][0], 'shift_uuid' => $b->uuid, 'effective_from' => '2026-06-01', 'priority' => 1]);

        $g1 = \App\Domain\People\Models\EmployeeGroup::create(['name' => 'G1', 'code' => 'G1']);
        $g2 = \App\Domain\People\Models\EmployeeGroup::create(['name' => 'G2', 'code' => 'G2']);
        $this->assign('shift', ['target_type' => 'employee_group', 'target_id' => $g1->id, 'shift_uuid' => $a->uuid]);
        try {
            $this->assign('shift', ['target_type' => 'employee_group', 'target_id' => $g2->id, 'shift_uuid' => $b->uuid]);
            $this->fail('expected group ambiguity rejection');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame('ambiguous_group_assignment', $e->getResponse()->getData(true)['code']);
        }
    }

    public function test_resolution_stays_deterministic_and_notes_ambiguity_when_rows_slip_through(): void
    {
        $e = $this->hire('Asha Rao');
        $a = $this->shift('A');
        $b = $this->shift('B', '10:00', '19:00');
        foreach ([$a, $b] as $s) { // bypass validation
            \App\Domain\Attendance\Models\ShiftAssignment::create(['shift_id' => $s->id, 'target_type' => 'tenant', 'target_id' => '*', 'effective_from' => '2026-01-01']);
        }
        $d = $this->resolve($e, '2026-10-12');
        $this->assertSame('B', $d->shiftCode); // highest id wins
        $this->assertStringContainsString('Ambiguous', implode(' ', $d->notes));
    }

    public function test_org_attributes_are_read_as_of_the_date_after_a_transfer(): void
    {
        $e = $this->hire('Asha Rao');
        $eng = $this->shift('ENG', '10:00', '19:00');
        $sal = $this->shift('SAL', '08:00', '17:00');
        $this->assign('shift', ['target_type' => 'department', 'target_id' => $this->org['department'][0], 'shift_uuid' => $eng->uuid]);
        $this->assign('shift', ['target_type' => 'department', 'target_id' => $this->org['department'][1], 'shift_uuid' => $sal->uuid]);
        app(AssignmentService::class)->change($e, ['department_id' => $this->org['department'][1]], '2026-10-15');

        $this->assertSame('ENG', $this->resolve($e, '2026-10-14')->shiftCode);
        $this->assertSame('SAL', $this->resolve($e, '2026-10-15')->shiftCode);
        $range = app(ScheduleResolver::class)->resolveRange($e->id, '2026-10-13', '2026-10-16');
        $this->assertSame(['ENG', 'ENG', 'SAL', 'SAL'], array_values(array_map(fn ($d) => $d->shiftCode, $range)));
    }

    public function test_future_dated_assignment_does_not_apply_early(): void
    {
        $e = $this->hire('Asha Rao');
        $a = $this->shift('A');
        $b = $this->shift('B', '10:00', '19:00');
        $this->assign('shift', ['target_type' => 'tenant', 'shift_uuid' => $a->uuid, 'effective_to' => '2026-11-30']);
        $this->assign('shift', ['target_type' => 'tenant', 'shift_uuid' => $b->uuid, 'effective_from' => '2026-12-01']);
        $this->assertSame('A', $this->resolve($e, '2026-11-30')->shiftCode);
        $this->assertSame('B', $this->resolve($e, '2026-12-01')->shiftCode);
    }

    public function test_cross_midnight_night_shift_belongs_to_start_date_and_dst_real_minutes(): void
    {
        $e = $this->hire('Ben Ford', ['location' => 1]); // London
        $night = $this->shift('NIGHT', '22:00', '06:00', ['timezone' => 'Europe/London']);
        $this->week('ALL', 'mon_sat');
        $this->assign('work_week', ['target_type' => 'tenant', 'work_week_uuid' => \App\Domain\Attendance\Models\WorkWeek::first()->uuid]);
        $this->assign('shift', ['target_type' => 'tenant', 'shift_uuid' => $night->uuid]);

        $normal = $this->resolve($e, '2026-10-12');
        $this->assertTrue($night->crosses_midnight);
        $this->assertSame(480, (int) $normal->start->diffInMinutes($normal->end));
        $this->assertSame('2026-10-12T21:00:00+00:00', $normal->start->toIso8601String()); // BST (UTC+1)
        $this->assertSame('2026-10-13T05:00:00+00:00', $normal->end->toIso8601String());
        $this->assertTrue($normal->windowStart < $normal->start && $normal->windowEnd > $normal->end);

        $fall = $this->resolve($e, '2026-10-24'); // clocks go back on Sun 25 Oct => 9 real hours
        $this->assertSame(540, (int) $fall->start->diffInMinutes($fall->end));
        $spring = $this->resolve($e, '2026-03-28'); // clocks go forward on 29 Mar => 7 real hours
        $this->assertSame(420, (int) $spring->start->diffInMinutes($spring->end));
    }

    public function test_flexible_and_split_shifts(): void
    {
        $e = $this->hire('Asha Rao');
        $flex = app(ShiftService::class)->create(['name' => 'Flex', 'code' => 'FLEX', 'kind' => 'flexible', 'timezone' => 'Asia/Kolkata', 'start_time' => '08:00', 'end_time' => '18:00',
            'flex_start_from' => '08:00', 'flex_start_to' => '10:00', 'core_start' => '11:00', 'core_end' => '15:00', 'required_minutes' => 480]);
        $split = app(ShiftService::class)->create(['name' => 'Split', 'code' => 'SPLIT', 'kind' => 'split', 'timezone' => 'Asia/Kolkata', 'start_time' => '08:00', 'end_time' => '20:00',
            'segments' => [['kind' => 'work', 'start' => '08:00', 'end' => '12:00'], ['kind' => 'work', 'start' => '16:00', 'end' => '20:00']]]);
        $this->assign('shift', ['target_type' => 'employee', 'target_id' => $e->id, 'shift_uuid' => $flex->uuid, 'effective_to' => '2026-10-31']);
        $this->assign('shift', ['target_type' => 'employee', 'target_id' => $e->id, 'shift_uuid' => $split->uuid, 'effective_from' => '2026-11-01']);
        $this->week();
        $this->assign('work_week', ['target_type' => 'tenant', 'work_week_uuid' => \App\Domain\Attendance\Models\WorkWeek::first()->uuid]);

        $f = $this->resolve($e, '2026-10-12');
        $this->assertSame(480, $f->requiredMinutes);
        $this->assertSame('02:30', $f->flexStartFrom->format('H:i')); // 08:00 IST in UTC
        $this->assertSame('04:30', $f->flexStartTo->format('H:i'));
        $this->assertSame('05:30', $f->coreStart->format('H:i'));
        $this->assertNotNull($f->coreEnd);

        $s = $this->resolve($e, '2026-11-02');
        $work = array_values(array_filter($s->segments, fn ($x) => $x->kind === 'work'));
        $this->assertCount(2, $work);
        $this->assertSame(480, $s->requiredMinutes);
    }

    public function test_rotation_cycles_including_before_anchor_and_14_day_cycle(): void
    {
        $e = $this->hire('Asha Rao');
        $m = $this->shift('M', '06:00', '14:00');
        $ev = $this->shift('E', '14:00', '22:00');
        $rot = app(RotationService::class)->create(['name' => 'MMEEO', 'code' => 'MMEEO', 'cycle_length' => 5, 'days' => [$m->uuid, $m->uuid, $ev->uuid, $ev->uuid, null]]);
        $this->assign('shift', ['target_type' => 'employee', 'target_id' => $e->id, 'rotation_uuid' => $rot->uuid, 'rotation_anchor' => '2026-10-05']);
        $codes = fn ($from, $to) => array_values(array_map(fn ($d) => $d->kind === 'working' ? $d->shiftCode : 'OFF', app(ScheduleResolver::class)->resolveRange($e->id, $from, $to)));
        $this->assertSame(['M', 'M', 'E', 'E', 'OFF', 'M'], $codes('2026-10-05', '2026-10-10'));
        $this->assertSame(['E', 'OFF'], $codes('2026-10-03', '2026-10-04') === ['E', 'OFF'] ? ['E', 'OFF'] : $codes('2026-10-03', '2026-10-04')); // before the anchor
        $d = $this->resolve($e, '2026-10-04'); // 1 day before anchor => day_index 5 (off)
        $this->assertSame('weekly_off', $d->kind);

        $days = [];
        for ($i = 1; $i <= 14; $i++) {
            $days[] = $i % 7 === 0 || $i % 7 === 6 ? null : $m->uuid;
        }
        $rot14 = app(RotationService::class)->create(['name' => 'Two weeks', 'code' => 'R14', 'cycle_length' => 14, 'days' => $days]);
        $e2 = $this->hire('Ben Lee');
        $this->assign('shift', ['target_type' => 'employee', 'target_id' => $e2->id, 'rotation_uuid' => $rot14->uuid, 'rotation_anchor' => '2026-09-01']);
        $this->assertSame('working', $this->resolve($e2, '2026-09-15')->kind); // 14 days later = day 1
        $this->assertSame('weekly_off', $this->resolve($e2, '2026-09-06')->kind); // day 6
    }

    public function test_work_week_half_custom_days_and_versioning(): void
    {
        $e = $this->hire('Asha Rao');
        $g = $this->shift('GEN', '09:00', '18:00', ['segments' => [['kind' => 'work', 'start' => '09:00', 'end' => '18:00'], ['kind' => 'break', 'paid' => false, 'start' => '13:00', 'end' => '14:00']], 'half_day_minutes' => 240]);
        $this->assign('shift', ['target_type' => 'tenant', 'shift_uuid' => $g->uuid]);
        $w = app(WorkWeekService::class)->create(['name' => 'Custom', 'code' => 'CUS', 'effective_from' => '2026-01-01', 'days' => [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full',
            5 => ['type' => 'custom', 'minutes' => 300], 6 => 'half', 7 => 'off']], $this->admin);
        $this->assign('work_week', ['target_type' => 'tenant', 'work_week_uuid' => $w->uuid]);
        $this->assertSame(480, $this->resolve($e, '2026-10-12')->requiredMinutes);   // 9h span - 1h unpaid break
        $this->assertSame(300, $this->resolve($e, '2026-10-16')->requiredMinutes);   // Friday custom
        $this->assertSame(240, $this->resolve($e, '2026-10-17')->requiredMinutes);   // Saturday half
        $this->assertSame('weekly_off', $this->resolve($e, '2026-10-18')->kind);

        $v2 = app(WorkWeekService::class)->newVersion($w, ['effective_from' => '2026-11-01', 'preset' => 'mon_fri'], $this->admin);
        $this->assertSame(2, $v2->version);
        $this->assertSame('2026-10-31', \App\Domain\Attendance\Models\WorkWeek::where('code', 'CUS')->where('version', 1)->value('effective_to'));
        $this->assertSame('weekly_off', $this->resolve($e, '2026-11-07')->kind); // Saturday now off
        $this->assertSame(240, $this->resolve($e, '2026-10-24')->requiredMinutes); // history unchanged
    }

    public function test_holidays_full_half_and_on_weekly_off(): void
    {
        $e = $this->hire('Asha Rao');
        $g = $this->shift('GEN');
        $this->assign('shift', ['target_type' => 'tenant', 'shift_uuid' => $g->uuid]);
        $this->week();
        $this->assign('work_week', ['target_type' => 'tenant', 'work_week_uuid' => \App\Domain\Attendance\Models\WorkWeek::first()->uuid]);
        $svc = app(HolidayService::class);
        $cal = $svc->createCalendar(['name' => 'India', 'code' => 'IN', 'country' => 'IN']);
        $svc->addHoliday($cal, ['name' => 'Diwali', 'date' => '2026-11-09']);
        $svc->addHoliday($cal, ['name' => 'Half day', 'date' => '2026-11-10', 'portion' => 'second_half']);
        $svc->addHoliday($cal, ['name' => 'Sunday fest', 'date' => '2026-11-15']);
        $this->assign('holiday', ['target_type' => 'country', 'target_id' => 'IN', 'calendar_uuid' => $cal->uuid]);

        $this->assertSame('holiday', $this->resolve($e, '2026-11-09')->kind);
        $half = $this->resolve($e, '2026-11-10');
        $this->assertSame('working', $half->kind);
        $this->assertSame('second_half', $half->holidayPortion);
        $this->assertLessThan(540, $half->requiredMinutes);
        $sun = $this->resolve($e, '2026-11-15');
        $this->assertSame('holiday', $sun->kind);
        $this->assertStringContainsString('weekly off', implode(' ', $sun->notes));
        $mine = $svc->forEmployee($e->id, '2026-11-01', '2026-11-30');
        $this->assertCount(3, $mine);

        // recurring generation is idempotent
        $svc->addHoliday($cal, ['name' => 'Republic Day', 'date' => '2026-01-26', 'recurrence' => ['rule' => 'fixed', 'month' => 1, 'day' => 26]]);
        $svc->addHoliday($cal, ['name' => 'Thanksgiving', 'date' => '2026-11-26', 'recurrence' => ['rule' => 'nth_weekday', 'month' => 11, 'weekday' => 4, 'nth' => 4]]);
        $first = $svc->generateRecurring($cal, 2027);
        $this->assertCount(2, $first['created']);
        $this->assertSame('2027-11-25', collect($first['created'])->firstWhere('name', 'Thanksgiving')['date']);
        $this->assertCount(0, $svc->generateRecurring($cal, 2027)['created']);
    }

    public function test_employment_period_rehire_and_roster_precedence(): void
    {
        $e = $this->hire('Asha Rao', ['joined_on' => '2026-10-10']);
        $g = $this->shift('GEN');
        $n = $this->shift('NIGHT', '22:00', '06:00');
        $this->assign('shift', ['target_type' => 'tenant', 'shift_uuid' => $g->uuid]);
        $this->week();
        $this->assign('work_week', ['target_type' => 'tenant', 'work_week_uuid' => \App\Domain\Attendance\Models\WorkWeek::first()->uuid]);
        $this->assertSame('not_scheduled', $this->resolve($e, '2026-10-09')->kind);
        $this->assertSame('working', $this->resolve($e, '2026-10-12')->kind);
        $e->update(['separated_on' => '2026-10-20']);
        $this->assertSame('not_scheduled', $this->resolve($e, '2026-10-21')->kind);

        // roster over assignment; cleared falls through; off entry
        $mk = fn ($date, $type, $shift) => \App\Domain\Attendance\Models\RosterEntry::create(['roster_id' => $this->rosterId(), 'employee_id' => $e->id, 'work_date' => $date, 'entry_type' => $type, 'shift_id' => $shift?->id, 'status' => 'published', 'published_at' => now()]);
        $mk('2026-10-13', 'shift', $n);
        $mk('2026-10-14', 'off', null);
        $mk('2026-10-15', 'cleared', null);
        $this->assertSame('NIGHT', $this->resolve($e, '2026-10-13')->shiftCode);
        $this->assertSame('roster', $this->resolve($e, '2026-10-13')->source);
        $this->assertSame('weekly_off', $this->resolve($e, '2026-10-14')->kind);
        $this->assertSame('GEN', $this->resolve($e, '2026-10-15')->shiftCode);
    }

    private function rosterId(): int
    {
        return \App\Domain\Attendance\Models\Roster::query()->first()?->id ?? \App\Domain\Attendance\Models\Roster::create(['name' => 'r', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'])->id;
    }

    public function test_shift_validation_rejects_bad_input(): void
    {
        $bad = fn (array $over) => app(ShiftService::class)->create($over + ['name' => 'X', 'code' => 'X'.rand(), 'timezone' => 'Asia/Kolkata', 'start_time' => '09:00', 'end_time' => '18:00']);
        foreach ([
            ['timezone' => 'Mars/Base'],
            ['segments' => [['kind' => 'work', 'start' => '09:00', 'end' => '13:00'], ['kind' => 'work', 'start' => '12:00', 'end' => '18:00']]],
            ['segments' => [['kind' => 'work', 'start' => '09:00', 'end' => '18:00'], ['kind' => 'break', 'start' => '19:00', 'end' => '19:30']]],
            ['kind' => 'flexible', 'flex_start_from' => '10:00', 'flex_start_to' => '08:00', 'required_minutes' => 480],
        ] as $case) {
            try {
                $bad($case);
                $this->fail('expected validation error '.json_encode($case));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $s = $this->shift('OK');
        $this->assertSame(540, $s->duration_minutes);
    }

    public function test_range_resolution_uses_bounded_queries(): void
    {
        $e = $this->hire('Asha Rao');
        $g = $this->shift('GEN');
        $this->assign('shift', ['target_type' => 'tenant', 'shift_uuid' => $g->uuid]);
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $r = app(ScheduleResolver::class)->resolveRange($e->id, '2026-10-01', '2026-10-31');
        $count = count(\DB::getQueryLog());
        $this->assertCount(31, $r);
        $this->assertLessThan(25, $count, "31-day range used $count queries");
    }
}
