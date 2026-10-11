<?php

namespace Tests\Unit\Attendance\Engine;

use App\Domain\Attendance\Engine\OvertimeCalculator;
use PHPUnit\Framework\TestCase;

class OvertimeTest extends TestCase
{
    private function ot(array $punchSpecs, array $overtime = [], array $sched = [], array $o = [])
    {
        return EngineFixtures::day($punchSpecs, ['overtime' => $overtime], $sched, $o);
    }

    public function test_post_shift_overtime_is_rounded_down_by_default(): void
    {
        $r = $this->ot(['09:00', '19:40']);
        $this->assertSame(90, $r->overtimeMinutes);
        $this->assertSame(90, $r->overtimeBreakdown['post_shift']);
        $this->assertSame(0, $r->overtimeBreakdown['pre_shift']);
    }

    public function test_below_minimum_is_zero(): void
    {
        $r = $this->ot(['09:00', '18:25']);
        $this->assertSame(0, $r->overtimeMinutes);
    }

    public function test_rounding_modes(): void
    {
        $this->assertSame(30, $this->ot(['09:00', '18:40'], ['rounding_mode' => 'down'])->overtimeMinutes);
        $this->assertSame(45, $this->ot(['09:00', '18:40'], ['rounding_mode' => 'up'])->overtimeMinutes);
        $this->assertSame(45, $this->ot(['09:00', '18:40'], ['rounding_mode' => 'nearest'])->overtimeMinutes);
        $this->assertSame(30, $this->ot(['09:00', '18:37'], ['rounding_mode' => 'nearest'])->overtimeMinutes);
    }

    public function test_rounding_helper_matrix(): void
    {
        $this->assertSame(50, OvertimeCalculator::round(50, 1, 'up'));
        $this->assertSame(60, OvertimeCalculator::round(46, 15, 'up'));
        $this->assertSame(45, OvertimeCalculator::round(52, 15, 'nearest'));
        $this->assertSame(60, OvertimeCalculator::round(53, 15, 'nearest'));
        $this->assertSame(45, OvertimeCalculator::round(52, 15, 'down'));
        $this->assertSame(30, OvertimeCalculator::round(37, 15, 'nearest'));
        $this->assertSame(45, OvertimeCalculator::round(38, 15, 'nearest'));
    }

    public function test_maximum_per_day_caps_the_total(): void
    {
        $r = $this->ot(['09:00', '23:30']);
        $this->assertSame(240, $r->overtimeMinutes);
        $this->assertSame('capped', EngineFixtures::step($r, 'overtime')['outcome']['per_type']['post_shift']['outcome']);
    }

    public function test_grace_ignores_small_overtime_but_counts_larger_in_full(): void
    {
        $this->assertSame(0, $this->ot(['09:00', '18:15'], ['grace_minutes' => 15, 'minimum_minutes' => 0, 'rounding_minutes' => 1])->overtimeMinutes);
        $this->assertSame(20, $this->ot(['09:00', '18:20'], ['grace_minutes' => 15, 'minimum_minutes' => 0, 'rounding_minutes' => 1])->overtimeMinutes);
    }

    public function test_threshold_is_subtracted(): void
    {
        $r = $this->ot(['09:00', '18:50'], ['threshold_minutes' => 30, 'minimum_minutes' => 0, 'rounding_minutes' => 1]);
        $this->assertSame(20, $r->overtimeMinutes);
    }

    public function test_pre_shift_is_off_by_default_and_works_when_enabled(): void
    {
        $this->assertSame(0, $this->ot(['08:00', '18:00'])->overtimeMinutes);
        $r = $this->ot(['08:00', '18:00'], ['pre_shift' => true]);
        $this->assertSame(60, $r->overtimeBreakdown['pre_shift']);
    }

    public function test_pre_and_post_shift_add_up(): void
    {
        $r = $this->ot(['07:30', '19:00'], ['pre_shift' => true]);
        $this->assertSame(90 + 60, $r->overtimeMinutes);
        $this->assertSame(['pre_shift' => 90, 'post_shift' => 60, 'weekly_off' => 0, 'holiday' => 0, 'rest_day' => 0], $r->overtimeBreakdown);
    }

    public function test_post_shift_flag_off_disables_it(): void
    {
        $this->assertSame(0, $this->ot(['09:00', '20:00'], ['post_shift' => false])->overtimeMinutes);
    }

    public function test_overtime_disabled(): void
    {
        $r = $this->ot(['09:00', '20:00'], ['enabled' => false]);
        $this->assertSame(0, $r->overtimeMinutes);
        $this->assertNull(EngineFixtures::exception($r, 'overtime'));
    }

    public function test_ineligible_employee_type_gets_no_overtime(): void
    {
        $r = $this->ot(['09:00', '20:00'], [], [], ['employeeTypeOvertimeEligible' => false]);
        $this->assertSame(0, $r->overtimeMinutes);
        $this->assertSame('employee type is not overtime eligible', EngineFixtures::step($r, 'overtime')['outcome']['ineligible_reason']);
    }

    public function test_ineligible_shift_gets_no_overtime(): void
    {
        $r = $this->ot(['09:00', '20:00'], [], ['overtimeEligible' => false]);
        $this->assertSame(0, $r->overtimeMinutes);
    }

    public function test_overtime_exception_only_when_approval_required(): void
    {
        $r = $this->ot(['09:00', '20:00']);
        $e = EngineFixtures::exception($r, 'overtime');
        $this->assertSame('info', $e['severity']);
        $this->assertSame('overtime.requires_approval', $e['rule']);
        $r2 = $this->ot(['09:00', '20:00'], ['requires_approval' => false]);
        $this->assertNull(EngineFixtures::exception($r2, 'overtime'));
        $this->assertSame(120, $r2->overtimeMinutes);
    }

    public function test_approved_overtime_is_only_carried_in_the_explanation(): void
    {
        $r = $this->ot(['09:00', '20:00'], [], [], ['approvedOvertimeMinutes' => 60]);
        $this->assertSame(120, $r->overtimeMinutes);
        $labels = array_column($r->explanation['lines'], 'value', 'label');
        $this->assertSame('1h', $labels['Overtime (approved)']);
        $this->assertSame('2h (post shift 2h)', $labels['Overtime (calculated)']);
    }

    public function test_work_on_weekly_off_is_weekly_off_overtime(): void
    {
        $sch = EngineFixtures::nonWorking('weekly_off');
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['10:00', '14:00']));
        $this->assertSame('present', $r->status);
        $this->assertSame(240, $r->overtimeBreakdown['weekly_off']);
        $this->assertSame(240, $r->overtimeMinutes);
        $this->assertNotNull(EngineFixtures::exception($r, 'unscheduled_work'));
        $this->assertTrue($r->flags['unscheduled_work']);
    }

    public function test_work_on_holiday_is_holiday_overtime(): void
    {
        $sch = EngineFixtures::nonWorking('holiday', ['holidayName' => 'Republic Day']);
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['10:00', '13:00']));
        $this->assertSame('present', $r->status);
        $this->assertSame(180, $r->overtimeBreakdown['holiday']);
    }

    public function test_work_on_a_not_scheduled_day_is_rest_day_overtime(): void
    {
        $sch = EngineFixtures::nonWorking('not_scheduled');
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['10:00', '13:00']));
        $this->assertSame(180, $r->overtimeBreakdown['rest_day']);
    }

    public function test_non_working_day_overtime_respects_the_type_flag_and_the_daily_cap(): void
    {
        $sch = EngineFixtures::nonWorking('weekly_off');
        $p = EngineFixtures::punchesOn($sch->date, $sch->timezone, ['08:00', '16:00']);
        $this->assertSame(240, EngineFixtures::run($sch, $p)->overtimeMinutes); // 480 capped at 240
        $this->assertSame(0, EngineFixtures::run($sch, $p, ['overtime' => ['weekly_off' => false]])->overtimeMinutes);
        $this->assertSame('present', EngineFixtures::run($sch, $p, ['overtime' => ['weekly_off' => false]])->status);
    }

    public function test_non_working_day_overtime_uses_net_after_breaks(): void
    {
        $sch = EngineFixtures::nonWorking('holiday');
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['09:00', '12:00', '13:00', '15:00']));
        $this->assertSame(300, $r->netMinutes); // 09–12 plus 13–15: five worked hours
        $this->assertSame(240, $r->overtimeMinutes); // default daily overtime cap
    }

    public function test_work_not_allowed_on_weekly_off_keeps_status_and_warns(): void
    {
        $sch = EngineFixtures::nonWorking('weekly_off');
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['10:00', '14:00']), ['weekend' => ['work_allowed' => false]]);
        $this->assertSame('weekly_off', $r->status);
        $this->assertSame(0, $r->overtimeMinutes);
        $this->assertSame('warning', EngineFixtures::exception($r, 'unscheduled_work')['severity']);
    }

    public function test_no_overtime_when_there_is_no_work_on_a_working_day(): void
    {
        $r = $this->ot(['09:00', '17:00']);
        $this->assertSame(0, $r->overtimeMinutes);
    }
}
