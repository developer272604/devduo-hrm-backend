<?php

namespace Tests\Unit\Attendance\Engine;

use App\Domain\Attendance\Engine\AttendanceCalculator;
use App\Domain\Attendance\Engine\CalculationInput;
use App\Domain\Attendance\Engine\Explainer;
use App\Domain\Attendance\Engine\Punch;
use App\Domain\Attendance\Engine\Simulator;
use App\Domain\Attendance\Support\PolicyRules;
use PHPUnit\Framework\TestCase;

class StatusAndRulesTest extends TestCase
{
    public function test_engine_version_is_semantic(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', AttendanceCalculator::ENGINE_VERSION);
    }

    public function test_full_day_is_present_and_confirmed(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], ['breaks' => ['mode' => 'none']]);
        $this->assertSame('present', $r->status);
        $this->assertSame('confirmed', $r->confidence);
        $this->assertSame([], $r->exceptions);
    }

    public function test_half_day_when_net_between_half_and_full(): void
    {
        $r = EngineFixtures::day(['09:00', '14:12']);
        $this->assertSame('half_day', $r->status);
        $this->assertSame(312, $r->netMinutes);
        $this->assertNotNull(EngineFixtures::exception($r, 'insufficient_hours'));
    }

    public function test_absent_when_below_half_day(): void
    {
        $this->assertSame('absent', EngineFixtures::day(['09:00', '11:00'])->status);
    }

    public function test_minimum_minutes_floor_makes_absent(): void
    {
        $r = EngineFixtures::day(['09:00', '14:00'], ['hours' => ['minimum_minutes' => 360]]);
        $this->assertSame('absent', $r->status);
    }

    public function test_no_punches_on_finished_working_day_is_absent(): void
    {
        $r = EngineFixtures::day([]);
        $this->assertSame('absent', $r->status);
        $this->assertSame('confirmed', $r->confidence);
        $this->assertSame(480, $r->requiredMinutes);
        $this->assertNull($r->firstIn);
    }

    public function test_no_punches_on_future_day_is_pending(): void
    {
        $r = EngineFixtures::day([], [], [], ['now' => EngineFixtures::at('-1 12:00')]);
        $this->assertSame('pending', $r->status);
        $this->assertSame('pending', $r->confidence);
    }

    public function test_no_punches_before_shift_end_today_is_pending_and_flag_can_disable(): void
    {
        $r = EngineFixtures::day([], [], [], ['now' => EngineFixtures::at('10:00')]);
        $this->assertSame('pending', $r->status);
        $r2 = EngineFixtures::day([], ['status' => ['future_days_pending' => false]], [], ['now' => EngineFixtures::at('10:00')]);
        $this->assertSame('absent', $r2->status);
    }

    public function test_open_session_during_the_day_is_pending_not_missing(): void
    {
        $r = EngineFixtures::day(['09:00 IN'], [], [], ['now' => EngineFixtures::at('12:00')]);
        $this->assertSame('pending', $r->status);
        $this->assertFalse($r->missingPunch);
        $this->assertNull(EngineFixtures::exception($r, 'missing_out'));
    }

    public function test_no_invented_times_without_auto_fill(): void
    {
        $r = EngineFixtures::day(['09:00 IN']);
        $this->assertNull($r->lastOut);
        $this->assertSame(0, $r->grossMinutes);
        $this->assertSame(0, $r->netMinutes);
        $this->assertFalse($r->flags['auto_filled']);
    }

    public function test_auto_fill_enabled_without_rule_still_invents_nothing(): void
    {
        $r = EngineFixtures::day(['09:00 IN'], ['missing_punch' => ['auto_fill' => true]]);
        $this->assertSame('missing_punch', $r->status);
        $this->assertSame(0, $r->netMinutes);
    }

    public function test_auto_fill_shift_end_uses_rule_and_flags_it(): void
    {
        $r = EngineFixtures::day(['09:00 IN'], ['missing_punch' => ['auto_fill' => true, 'auto_fill_rule' => 'shift_end'], 'breaks' => ['mode' => 'none']]);
        $this->assertSame('present', $r->status);
        $this->assertTrue($r->flags['auto_filled']);
        $this->assertSame(540, $r->grossMinutes);
        $this->assertSame('info', EngineFixtures::exception($r, 'missing_out')['severity']);
        $this->assertSame('auto-filled by approved rule', EngineFixtures::step($r, 'missing_punch')['outcome']['auto_filled']);
        $this->assertSame(['event:1'], $r->punchIdsUsed);
    }

    public function test_auto_fill_shift_start_fills_missing_in(): void
    {
        $r = EngineFixtures::day(['18:00 OUT'], ['missing_punch' => ['auto_fill' => true, 'auto_fill_rule' => 'shift_start'], 'breaks' => ['mode' => 'none']]);
        $this->assertSame('present', $r->status);
        $this->assertSame(540, $r->grossMinutes);
    }

    public function test_holiday_and_weekly_off_without_work(): void
    {
        $this->assertSame('holiday', EngineFixtures::run(EngineFixtures::nonWorking('holiday', ['holidayName' => 'Diwali']), [])->status);
        $this->assertSame('weekly_off', EngineFixtures::run(EngineFixtures::nonWorking('weekly_off'), [])->status);
        $this->assertSame('not_scheduled', EngineFixtures::run(EngineFixtures::nonWorking('not_scheduled'), [])->status);
        $r = EngineFixtures::run(EngineFixtures::nonWorking('weekly_off'), []);
        $this->assertSame(0, $r->requiredMinutes);
        $this->assertSame('confirmed', $r->confidence);
    }

    public function test_holiday_that_is_also_weekly_off_resolves_to_what_the_resolver_says(): void
    {
        $r = EngineFixtures::run(EngineFixtures::nonWorking('holiday', ['holidayName' => 'Sunday Holiday', 'workWeekId' => 1]), []);
        $this->assertSame('holiday', $r->status);
    }

    public function test_half_day_holiday_shifts_expected_window_and_requirement(): void
    {
        $r = EngineFixtures::day(['09:00', '13:30'], ['breaks' => ['mode' => 'none']], ['holidayPortion' => 'second_half', 'holidayName' => 'Half', 'requiredMinutes' => 240, 'halfDayMinutes' => 120]);
        $this->assertSame('present', $r->status);
        $this->assertSame(240, $r->requiredMinutes);
        $this->assertSame(0, $r->earlyExitMinutes); // expected end moved to 13:30 (first half of 9h span kept)
    }

    public function test_leave_full_day(): void
    {
        $r = EngineFixtures::day([], [], [], ['leave' => ['minutes' => 480, 'portion' => 'full', 'reference' => 'L1']]);
        $this->assertSame('leave', $r->status);
        $this->assertSame(0, $r->requiredMinutes);
    }

    public function test_leave_half_day_reduces_requirement(): void
    {
        $r = EngineFixtures::day(['13:30', '18:00'], ['breaks' => ['mode' => 'none']], [], ['leave' => ['minutes' => 240, 'portion' => 'first_half', 'reference' => 'L2']]);
        $this->assertSame(240, $r->requiredMinutes);
        $this->assertSame('present', $r->status);
        $this->assertTrue($r->flags['leave_partial']);
        $this->assertSame(0, $r->lateMinutes);
    }

    public function test_on_duty_full_day(): void
    {
        $od = [['start' => EngineFixtures::at('09:00'), 'end' => EngineFixtures::at('18:00'), 'type' => 'client_visit', 'id' => 'od:1']];
        $r = EngineFixtures::day([], [], [], ['onDuty' => $od]);
        $this->assertSame('on_duty', $r->status);
        $this->assertSame('on_duty', $r->workMode);
        $this->assertSame(0, $r->lateMinutes);
    }

    public function test_remote_work_on_duty_type(): void
    {
        $od = [['start' => EngineFixtures::at('09:00'), 'end' => EngineFixtures::at('18:00'), 'type' => 'remote_work', 'id' => 'od:2']];
        $this->assertSame('remote_work', EngineFixtures::day([], [], [], ['onDuty' => $od])->status);
    }

    public function test_partial_on_duty_counts_as_worked_minutes(): void
    {
        $od = [['start' => EngineFixtures::at('13:00'), 'end' => EngineFixtures::at('18:00'), 'type' => 'training', 'id' => 'od:3']];
        $r = EngineFixtures::day(['09:00', '13:00'], ['breaks' => ['mode' => 'none']], [], ['onDuty' => $od]);
        $this->assertSame(540, $r->netMinutes);
        $this->assertSame('present', $r->status);
    }

    public function test_remote_approved_with_punches_is_remote_work(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], ['breaks' => ['mode' => 'none']], [], ['remoteApproved' => true]);
        $this->assertSame('remote_work', $r->status);
        $this->assertSame('remote', $r->workMode);
    }

    public function test_remote_context_suppresses_geofence_exception(): void
    {
        $sch = EngineFixtures::schedule();
        $p = EngineFixtures::punchesOn($sch->date, $sch->timezone, ['09:00', '18:00'], ['geofence' => 'outside']);
        $r = EngineFixtures::run($sch, $p, ['breaks' => ['mode' => 'none']], ['remoteApproved' => true]);
        $this->assertNull(EngineFixtures::exception($r, 'outside_geofence'));
        $this->assertSame('confirmed', $r->confidence);
    }

    public function test_outside_geofence_raises_warning_and_review(): void
    {
        $sch = EngineFixtures::schedule();
        $p = EngineFixtures::punchesOn($sch->date, $sch->timezone, ['09:00', '18:00'], ['geofence' => 'outside']);
        $r = EngineFixtures::run($sch, $p, ['breaks' => ['mode' => 'none']]);
        $this->assertSame('warning', EngineFixtures::exception($r, 'outside_geofence')['severity']);
        $this->assertSame('review_required', $r->confidence);
        $this->assertSame('present', $r->status);
    }

    public function test_failed_required_photo_needs_review_with_neutral_wording(): void
    {
        $sch = EngineFixtures::schedule();
        $p = EngineFixtures::punchesOn($sch->date, $sch->timezone, ['09:00', '18:00'], ['verification' => 'failed']);
        $r = EngineFixtures::run($sch, $p, ['punch_restrictions' => ['require_photo' => true]]);
        $this->assertSame('review_required', $r->confidence);
        $e = EngineFixtures::exception($r, 'integrity_review');
        $this->assertSame('Attendance integrity review required', $e['title']);
        $this->assertStringNotContainsStringIgnoringCase('fraud', json_encode($r->explanation));
    }

    public function test_no_policy_adds_exception_and_uses_defaults(): void
    {
        $sch = EngineFixtures::schedule();
        $in = new CalculationInput($sch, [], EngineFixtures::punchesOn($sch->date, $sch->timezone, ['09:00', '18:00']), EngineFixtures::at('+2 00:00'));
        $r = AttendanceCalculator::calculate($in);
        $this->assertSame('action_required', EngineFixtures::exception($r, 'no_policy')['severity']);
        $this->assertTrue($r->flags['no_policy_defaults']);
        $this->assertSame('review_required', $r->confidence);
        $this->assertSame('present', $r->status);
    }

    public function test_working_day_without_shift_id_raises_no_shift(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], [], ['shiftId' => null]);
        $this->assertSame('action_required', EngineFixtures::exception($r, 'no_shift')['severity']);
    }

    public function test_rest_violation_flag_only(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], [], [], ['compliance' => ['min_rest_minutes' => 660], 'previousDayEndUtc' => EngineFixtures::at('-1 23:00')->toIso8601String()]);
        $this->assertTrue($r->flags['rest_violation']);
        $this->assertSame('warning', EngineFixtures::exception($r, 'rest_violation')['severity']);
        $this->assertSame(540, $r->grossMinutes);
        $ok = EngineFixtures::day(['09:00', '18:00'], [], [], ['compliance' => ['min_rest_minutes' => 660], 'previousDayEndUtc' => EngineFixtures::at('-1 18:00')->toIso8601String()]);
        $this->assertFalse($ok->flags['rest_violation']);
    }

    public function test_max_daily_minutes_flag_does_not_alter_numbers(): void
    {
        $r = EngineFixtures::day(['09:00', '20:00'], [], [], ['compliance' => ['max_daily_minutes' => 600]]);
        $this->assertTrue($r->flags['max_daily_exceeded']);
        $this->assertSame(660, $r->grossMinutes);
    }

    public function test_critical_is_never_used(): void
    {
        $r = EngineFixtures::day(['09:00 IN', '09:10 IN', '10:00 OUT', '10:30 OUT'], [], ['shiftId' => null]);
        foreach ($r->exceptions as $e) {
            $this->assertNotSame('critical', $e['severity']);
        }
    }

    // ------------------------------------------------------------------ determinism

    public function test_same_input_twice_gives_identical_arrays_and_steps(): void
    {
        $sch = EngineFixtures::schedule();
        $in = EngineFixtures::input($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['09:20', '13:00', '14:00', '17:00']));
        $a = AttendanceCalculator::calculate($in);
        $b = AttendanceCalculator::calculate($in);
        $this->assertSame(json_encode($a->toArray()), json_encode($b->toArray()));
        $this->assertSame(json_encode($a->steps), json_encode($b->steps));
        $this->assertSame(json_encode($a->explanation), json_encode($b->explanation));
    }

    public function test_steps_are_json_safe(): void
    {
        $r = EngineFixtures::day(['09:20', '13:00', '14:00', '17:00']);
        $this->assertNotFalse(json_encode($r->steps));
        foreach ($r->steps as $s) {
            $this->assertSame(['step', 'rule', 'inputs', 'outcome'], array_keys($s));
        }
    }

    // ------------------------------------------------------------------ simulator

    public function test_simulator_reports_grace_change_effect(): void
    {
        $sch = EngineFixtures::schedule();
        $in = EngineFixtures::input($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['09:08', '18:30']));
        $cmp = Simulator::compare($in, ['late' => ['grace_minutes' => 5]]);
        $this->assertTrue($cmp['changed']);
        $fields = array_column($cmp['differences'], 'field');
        $this->assertContains('late_minutes', $fields);
        $d = $cmp['differences'][array_search('late_minutes', $fields)];
        $this->assertSame([0, 3], [$d['before'], $d['after']]);
        $this->assertSame(0, $cmp['before']['late_minutes']);
        $this->assertSame(10, $in->rules['late']['grace_minutes']); // untouched
    }

    public function test_simulator_unchanged_when_override_has_no_effect(): void
    {
        $sch = EngineFixtures::schedule();
        $in = EngineFixtures::input($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['09:00', '18:30']));
        $this->assertFalse(Simulator::compare($in, ['late' => ['grace_minutes' => 5]])['changed']);
    }

    public function test_simulator_summary_counts_status_transitions(): void
    {
        $sch = EngineFixtures::schedule();
        $in = EngineFixtures::input($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['10:30', '19:30']));
        $cmps = [];
        for ($i = 0; $i < 3; $i++) {
            $cmps[] = Simulator::compare($in, ['late' => ['half_day_after_minutes' => 60]]);
        }
        $cmps[] = Simulator::compare(EngineFixtures::input($sch, EngineFixtures::punchesOn($sch->date, $sch->timezone, ['09:00', '18:30'])), ['late' => ['half_day_after_minutes' => 60]]);
        $sum = Simulator::summarize($cmps);
        $this->assertSame(4, $sum['total']);
        $this->assertSame(3, $sum['changed']);
        $this->assertSame('3 records would change from Present to Half day', $sum['status_transitions'][0]['message']);
    }

    // ------------------------------------------------------------------ explainer

    public function test_explainer_half_day_example(): void
    {
        $r = EngineFixtures::day(['09:52', '15:04'], ['breaks' => ['mode' => 'none']], [], ['policyLabel' => 'India General Attendance Policy v3']);
        $this->assertSame('Half day', $r->explanation['headline']);
        $v = array_column($r->explanation['lines'], 'value', 'label');
        $this->assertSame('09:00–18:00 IST (Asia/Kolkata) [GEN]', $v['Scheduled shift']);
        $this->assertSame('8h', $v['Required full day']);
        $this->assertSame('4h', $v['Required half day']);
        $this->assertSame('5h 12m', $v['Net work']);
        $this->assertSame('42m (grace 10m, counted beyond grace)', $v['Late']);
        $this->assertSame('"India General Attendance Policy v3"', $v['Policy']);
        $this->assertSame('Half day', $v['Result']);
    }

    public function test_explainer_missing_punch_suggests_regularization(): void
    {
        $r = EngineFixtures::day(['09:00 IN']);
        $this->assertSame('Missing punch', $r->explanation['headline']);
        $this->assertContains('Request regularization', $r->explanation['actions']);
    }

    public function test_explainer_mentions_dropped_duplicates(): void
    {
        $r = EngineFixtures::day(['09:00:00 IN', '09:00:10 IN', '18:00 OUT']);
        $this->assertStringContainsString('duplicate', implode(' ', $r->explanation['facts']));
    }

    public function test_day_reconstruction_orders_chronologically_in_shift_timezone(): void
    {
        $rows = Explainer::dayReconstruction([
            ['at' => '2026-03-03T04:45:00Z', 'label' => 'Regularization submitted', 'kind' => 'request'],
            ['at' => '2026-03-02T12:37:00Z', 'label' => 'Biometric OUT', 'kind' => 'punch'],
            ['at' => '2026-03-02T03:24:00Z', 'label' => 'Biometric IN', 'kind' => 'punch'],
        ], 'Asia/Kolkata', '2026-03-02');
        $this->assertSame(['Biometric IN', 'Biometric OUT', 'Regularization submitted'], array_column($rows, 'label'));
        $this->assertSame('08:54', $rows[0]['display']);
        $this->assertSame('Next day 10:15', $rows[2]['display']);
    }

    public function test_virtual_punch_class_is_unchanged_contract(): void
    {
        $p = new Punch('x', EngineFixtures::at('09:00'), 'IN');
        $this->assertSame('raw', $p->origin);
    }
}
