<?php

namespace Tests\Unit\Attendance\Engine;

use App\Domain\Attendance\Engine\CalculationRun;
use PHPUnit\Framework\TestCase;

class GraceAndLateEarlyTest extends TestCase
{
    private function day(array $punches, array $late = [], array $early = [], array $o = [])
    {
        return EngineFixtures::day($punches, array_filter(['late' => $late, 'early_exit' => $early]), [], $o);
    }

    public function test_arrival_within_grace_is_on_time_in_both_modes(): void
    {
        foreach (['beyond_grace', 'from_start'] as $mode) {
            $r = $this->day(['09:08', '18:02'], ['grace_minutes' => 10, 'grace_mode' => $mode]);
            $this->assertSame(0, $r->lateMinutes, $mode);
            $this->assertNull(EngineFixtures::exception($r, 'late_arrival'));
        }
    }

    public function test_beyond_grace_counts_only_minutes_after_the_grace(): void
    {
        $r = $this->day(['09:18', '18:02'], ['grace_minutes' => 10, 'grace_mode' => 'beyond_grace']);
        $this->assertSame(8, $r->lateMinutes);
    }

    public function test_from_start_counts_the_whole_delay_once_grace_is_exceeded(): void
    {
        $r = $this->day(['09:18', '18:02'], ['grace_minutes' => 10, 'grace_mode' => 'from_start']);
        $this->assertSame(18, $r->lateMinutes);
    }

    public function test_arrival_exactly_at_end_of_grace_is_on_time(): void
    {
        $r = $this->day(['09:10', '18:02'], ['grace_mode' => 'from_start']);
        $this->assertSame(0, $r->lateMinutes);
    }

    public function test_one_minute_past_grace_from_start_counts_eleven(): void
    {
        $r = $this->day(['09:11', '18:02'], ['grace_mode' => 'from_start']);
        $this->assertSame(11, $r->lateMinutes);
        $r2 = $this->day(['09:11', '18:02'], ['grace_mode' => 'beyond_grace']);
        $this->assertSame(1, $r2->lateMinutes);
    }

    public function test_seconds_inside_the_grace_minute_do_not_make_late(): void
    {
        $r = $this->day(['09:10:30', '18:02'], ['grace_mode' => 'beyond_grace']);
        $this->assertSame(0, $r->lateMinutes);
    }

    public function test_early_arrival_is_never_late(): void
    {
        $r = $this->day(['08:30', '18:02']);
        $this->assertSame(0, $r->lateMinutes);
    }

    public function test_zero_grace_counts_every_minute(): void
    {
        $r = $this->day(['09:03', '18:02'], ['grace_minutes' => 0, 'grace_mode' => 'beyond_grace']);
        $this->assertSame(3, $r->lateMinutes);
    }

    public function test_early_exit_beyond_grace(): void
    {
        $r = $this->day(['09:00', '17:42'], [], ['grace_minutes' => 10, 'grace_mode' => 'beyond_grace']);
        $this->assertSame(8, $r->earlyExitMinutes);
        $this->assertNotNull(EngineFixtures::exception($r, 'early_departure'));
    }

    public function test_early_exit_from_start_mode_counts_whole_gap(): void
    {
        $r = $this->day(['09:00', '17:42'], [], ['grace_minutes' => 10, 'grace_mode' => 'from_start']);
        $this->assertSame(18, $r->earlyExitMinutes);
    }

    public function test_early_exit_within_grace_is_zero(): void
    {
        $r = $this->day(['09:00', '17:55']);
        $this->assertSame(0, $r->earlyExitMinutes);
    }

    public function test_leaving_late_is_not_early(): void
    {
        $r = $this->day(['09:00', '19:30']);
        $this->assertSame(0, $r->earlyExitMinutes);
    }

    public function test_static_lateness_helper_matrix(): void
    {
        $this->assertSame(0, CalculationRun::lateness(-300, 10, 'beyond_grace'));
        $this->assertSame(0, CalculationRun::lateness(600, 10, 'from_start'));
        $this->assertSame(10, CalculationRun::lateness(1200, 10, 'beyond_grace'));
        $this->assertSame(20, CalculationRun::lateness(1200, 10, 'from_start'));
    }

    public function test_late_allowance_within_limit_is_info_and_not_chargeable(): void
    {
        $r = $this->day(['09:30', '18:30'], ['allowed_occurrences_per_month' => 3], [], ['monthToDate' => ['late_count' => 1, 'early_count' => 0, 'late_minutes' => 10]]);
        $this->assertFalse($r->flags['late_counts_as_occurrence']);
        $this->assertSame(2, $r->flags['late_occurrence_number']);
        $this->assertSame('info', EngineFixtures::exception($r, 'late_arrival')['severity']);
    }

    public function test_late_allowance_exceeded_flags_occurrence_and_warns(): void
    {
        $r = $this->day(['09:30', '18:30'], ['allowed_occurrences_per_month' => 3], [], ['monthToDate' => ['late_count' => 3, 'early_count' => 0, 'late_minutes' => 60]]);
        $this->assertTrue($r->flags['late_counts_as_occurrence']);
        $this->assertSame(4, $r->flags['late_occurrence_number']);
        $this->assertSame('warning', EngineFixtures::exception($r, 'late_arrival')['severity']);
    }

    public function test_no_allowance_configured_defaults_to_warning_and_not_chargeable(): void
    {
        $r = $this->day(['09:30', '18:30'], [], [], ['monthToDate' => ['late_count' => 9, 'early_count' => 0, 'late_minutes' => 0]]);
        $this->assertFalse($r->flags['late_counts_as_occurrence']);
        $this->assertSame('warning', EngineFixtures::exception($r, 'late_arrival')['severity']);
    }

    public function test_escalation_after_occurrences_raises_action_required(): void
    {
        $r = $this->day(['09:30', '18:30'], ['allowed_occurrences_per_month' => 2, 'escalate_after_occurrences' => 4], [], ['monthToDate' => ['late_count' => 3, 'early_count' => 0, 'late_minutes' => 0]]);
        $this->assertSame('action_required', EngineFixtures::exception($r, 'late_arrival')['severity']);
    }

    public function test_monthly_threshold_minutes_escalates(): void
    {
        $r = $this->day(['09:30', '18:30'], ['monthly_threshold_minutes' => 60], [], ['monthToDate' => ['late_count' => 1, 'early_count' => 0, 'late_minutes' => 50]]);
        $this->assertSame('action_required', EngineFixtures::exception($r, 'late_arrival')['severity']);
        $step = EngineFixtures::step($r, 'late.allowance');
        $this->assertTrue($step['outcome']['monthly_threshold_exceeded']);
    }

    public function test_penalty_reference_is_recorded_in_the_trace_but_not_interpreted(): void
    {
        $r = $this->day(['09:30', '18:30'], ['penalty_reference' => 'LOP-HALF-3']);
        $this->assertSame('LOP-HALF-3', EngineFixtures::step($r, 'late')['inputs']['penalty_reference']);
        $this->assertSame('LOP-HALF-3', EngineFixtures::step($r, 'late.allowance')['outcome']['penalty_reference']);
    }

    public function test_half_day_after_late_downgrades_a_full_day(): void
    {
        $r = $this->day(['10:45', '19:30'], ['half_day_after_minutes' => 120]);
        $this->assertSame('present', $r->status);
        $r2 = $this->day(['10:45', '19:30'], ['half_day_after_minutes' => 60]);
        $this->assertSame('half_day', $r2->status);
        $this->assertTrue($r2->flags['half_day_by_late']);
    }

    public function test_early_exit_allowance_and_threshold(): void
    {
        $r = $this->day(['09:00', '16:00'], [], ['allowed_occurrences_per_month' => 1, 'threshold_minutes' => 60], ['monthToDate' => ['late_count' => 0, 'early_count' => 1, 'late_minutes' => 0]]);
        $this->assertTrue($r->flags['early_counts_as_occurrence']);
        $this->assertSame('action_required', EngineFixtures::exception($r, 'early_departure')['severity']);
    }

    public function test_early_exit_is_deferred_while_the_day_is_in_progress(): void
    {
        $sch = EngineFixtures::schedule();
        $now = EngineFixtures::at('15:00');
        $r = EngineFixtures::run($sch, EngineFixtures::punches('09:00', '14:00'), [], ['now' => $now]);
        $this->assertSame(0, $r->earlyExitMinutes);
        $this->assertSame('pending', $r->status);
        $this->assertSame('pending', $r->confidence);
    }
}
