<?php

namespace Tests\Unit\Attendance\Engine;

use App\Domain\Attendance\Engine\Punch;
use PHPUnit\Framework\TestCase;

class PairingAndPunchesTest extends TestCase
{
    private function strategy(string $s, array $extra = []): array
    {
        return ['pairing' => ['strategy' => $s] + $extra];
    }

    public function test_strict_pairs_in_and_out(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00']);
        $this->assertCount(1, $r->pairs);
        $this->assertSame(540, $r->pairs[0]['minutes']);
        $this->assertSame('present', $r->status);
        $this->assertSame('confirmed', $r->confidence);
        $this->assertSame(['event:1', 'event:2'], $r->punchIdsUsed);
    }

    public function test_strict_trusts_declared_directions(): void
    {
        // declared OUT then IN: the OUT has nothing before it
        $r = EngineFixtures::day(['09:00 OUT', '18:00 IN']);
        $this->assertSame('incomplete', $r->status);
        $this->assertSame(['missing_in', 'missing_out'], array_column(EngineFixtures::step($r, 'pairing')['outcome']['anomalies'], 'kind'));
    }

    public function test_two_in_punches_are_unpaired_and_review_required(): void
    {
        $r = EngineFixtures::day(['09:00 IN', '09:30 IN', '18:00 OUT']);
        $this->assertSame('incomplete', $r->status);
        $this->assertSame('review_required', $r->confidence);
        $this->assertNotNull(EngineFixtures::exception($r, 'unpaired_punch'));
        $this->assertSame('action_required', EngineFixtures::exception($r, 'unpaired_punch')['severity']);
        // earlier IN is kept: the single session spans 09:00-18:00
        $this->assertSame(540, $r->pairs[0]['minutes']);
        $this->assertSame(['event:1', 'event:3'], $r->punchIdsUsed);
    }

    public function test_two_out_punches_are_unpaired(): void
    {
        $r = EngineFixtures::day(['09:00 IN', '17:00 OUT', '18:00 OUT']);
        $this->assertSame('incomplete', $r->status);
        $this->assertNotNull(EngineFixtures::exception($r, 'unpaired_punch'));
        $this->assertSame(480, $r->pairs[0]['minutes']);
        $this->assertFalse($r->missingPunch);
    }

    public function test_single_in_punch_on_finished_day_is_missing_out(): void
    {
        $r = EngineFixtures::day(['09:00 IN']);
        $this->assertSame('missing_punch', $r->status);
        $this->assertTrue($r->missingPunch);
        $this->assertSame('review_required', $r->confidence);
        $this->assertSame('action_required', EngineFixtures::exception($r, 'missing_out')['severity']);
        $this->assertNull($r->pairs[0]['out']);
        $this->assertNull($r->lastOut);
        $this->assertNotNull($r->firstIn);
    }

    public function test_single_out_punch_is_missing_in(): void
    {
        $r = EngineFixtures::day(['18:00 OUT']);
        $this->assertSame('missing_punch', $r->status);
        $this->assertNotNull(EngineFixtures::exception($r, 'missing_in'));
        $this->assertNull($r->firstIn);
    }

    public function test_unknown_directions_alternate_starting_with_in(): void
    {
        $r = EngineFixtures::day(['09:00 UNK', '13:00 UNK', '14:00 UNK', '18:00 UNK']);
        $this->assertCount(2, $r->pairs);
        $this->assertSame(240, $r->pairs[0]['minutes']);
        $this->assertSame(240, $r->pairs[1]['minutes']);
        $this->assertSame('present', $r->status);
    }

    public function test_mixed_known_and_unknown_directions_are_deterministic(): void
    {
        // IN, UNKNOWN(->OUT), UNKNOWN(->IN), OUT
        $r = EngineFixtures::day(['09:00 IN', '13:00 UNK', '14:00 UNK', '18:00 OUT']);
        $this->assertCount(2, $r->pairs);
        $this->assertSame('present', $r->status);
        $this->assertSame(60, $r->breakMinutes);
    }

    public function test_unknown_after_declared_out_starts_a_new_in(): void
    {
        $r = EngineFixtures::day(['09:00 IN', '12:00 OUT', '13:00 UNK', '18:00 UNK']);
        $this->assertCount(2, $r->pairs);
        $this->assertSame(180, $r->pairs[0]['minutes']);
        $this->assertSame(300, $r->pairs[1]['minutes']);
    }

    public function test_alternating_ignores_declared_directions(): void
    {
        $r = EngineFixtures::day(['09:00 OUT', '18:00 OUT'], $this->strategy('alternating'));
        $this->assertCount(1, $r->pairs);
        $this->assertSame(540, $r->pairs[0]['minutes']);
        $this->assertSame('present', $r->status);
    }

    public function test_alternating_odd_count_leaves_a_missing_out(): void
    {
        $r = EngineFixtures::day(['09:00 UNK', '13:00 UNK', '14:00 UNK'], $this->strategy('alternating'));
        $this->assertSame('missing_punch', $r->status);
        $this->assertNotNull(EngineFixtures::exception($r, 'missing_out'));
        $this->assertSame(240, $r->pairs[0]['minutes']);
        $this->assertNull($r->pairs[1]['out']);
    }

    public function test_first_last_uses_first_and_last_and_ignores_middle_when_odd(): void
    {
        $r = EngineFixtures::day(['09:00 UNK', '11:00 UNK', '13:00 UNK', '14:00 UNK', '18:00 UNK'], $this->strategy('first_last'));
        // 3 intermediates (odd) => ignored
        $this->assertCount(1, $r->pairs);
        $this->assertSame(540, $r->pairs[0]['minutes']);
        $this->assertSame(['event:1', 'event:5'], $r->punchIdsUsed);
    }

    public function test_first_last_uses_intermediates_as_breaks_in_punch_mode_when_even(): void
    {
        $r = EngineFixtures::day(['09:00 UNK', '13:00 UNK', '14:00 UNK', '18:00 UNK'], $this->strategy('first_last'));
        $this->assertCount(2, $r->pairs);
        $this->assertSame(60, $r->breakMinutes);
    }

    public function test_first_last_ignores_intermediates_when_break_mode_is_not_punch(): void
    {
        $r = EngineFixtures::day(['09:00 UNK', '13:00 UNK', '14:00 UNK', '18:00 UNK'], $this->strategy('first_last') + ['breaks' => ['mode' => 'none']]);
        $this->assertCount(1, $r->pairs);
        $this->assertSame(0, $r->breakMinutes);
        $this->assertSame(540, $r->grossMinutes);
    }

    public function test_first_last_single_punch_is_missing_out(): void
    {
        $r = EngineFixtures::day(['09:00 UNK'], $this->strategy('first_last'));
        $this->assertSame('missing_punch', $r->status);
    }

    public function test_max_session_minutes_caps_instead_of_inventing(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], ['pairing' => ['max_session_minutes' => 300]]);
        $this->assertSame(300, $r->grossMinutes);
        $this->assertTrue($r->flags['session_capped']);
        $this->assertSame('review_required', $r->confidence);
        $this->assertSame('half_day', $r->status);
    }

    // ------------------------------------------------------------------ dedup / window

    public function test_duplicates_within_window_keep_the_earliest(): void
    {
        $r = EngineFixtures::day(['09:00:00 IN', '09:00:20 IN', '18:00 OUT']);
        $this->assertSame('present', $r->status);
        $this->assertSame(['event:1', 'event:3'], $r->punchIdsUsed);
        $norm = EngineFixtures::step($r, 'punches.normalize');
        $this->assertSame('event:2', $norm['outcome']['duplicates_dropped'][0]['dropped']);
        $this->assertSame('event:1', $norm['outcome']['duplicates_dropped'][0]['kept']);
        $this->assertNull(EngineFixtures::exception($r, 'unpaired_punch'));
    }

    public function test_same_direction_punches_outside_the_window_are_distinct(): void
    {
        $r = EngineFixtures::day(['09:00:00 IN', '09:02:00 IN', '18:00 OUT']);
        $this->assertSame('incomplete', $r->status);
        $this->assertSame([], EngineFixtures::step($r, 'punches.normalize')['outcome']['duplicates_dropped']);
    }

    public function test_different_directions_close_together_are_never_deduplicated(): void
    {
        $r = EngineFixtures::day(['09:00:00 IN', '09:00:10 OUT', '09:30 IN', '18:00 OUT']);
        $this->assertSame([], EngineFixtures::step($r, 'punches.normalize')['outcome']['duplicates_dropped']);
        $this->assertCount(2, $r->pairs);
    }

    public function test_duplicate_window_is_configurable_and_zero_only_merges_identical_instants(): void
    {
        $r = EngineFixtures::day(['09:00:00 IN', '09:00:30 IN', '18:00 OUT'], ['pairing' => ['duplicate_window_seconds' => 0]]);
        $this->assertSame('incomplete', $r->status);
        $r2 = EngineFixtures::day(['09:00:00 IN', '09:00:00 IN', '18:00 OUT'], ['pairing' => ['duplicate_window_seconds' => 0]]);
        $this->assertSame('present', $r2->status);
    }

    public function test_punch_outside_the_accepted_window_is_flagged_not_used(): void
    {
        // window = start-4h .. end+6h => 05:00 .. 24:00
        $r = EngineFixtures::day(['03:00 IN', '09:00 IN', '18:00 OUT']);
        $this->assertSame('present', $r->status);
        $e = EngineFixtures::exception($r, 'unexpected_punch');
        $this->assertSame('info', $e['severity']);
        $this->assertSame(['event:1'], $e['details']['punch_ids']);
        $this->assertSame(['event:2', 'event:3'], $r->punchIdsUsed);
    }

    public function test_window_bounds_are_inclusive(): void
    {
        $r = EngineFixtures::day(['05:00 IN', '+1 00:00 OUT']);
        $this->assertNull(EngineFixtures::exception($r, 'unexpected_punch'));
        $this->assertCount(1, $r->pairs);
    }

    public function test_input_order_does_not_matter(): void
    {
        $sch = EngineFixtures::schedule();
        $p = EngineFixtures::punches('09:00', '13:00', '14:00', '18:00');
        $a = EngineFixtures::run($sch, $p)->toArray();
        $b = EngineFixtures::run($sch, array_reverse($p))->toArray();
        $c = EngineFixtures::run($sch, [$p[2], $p[0], $p[3], $p[1]])->toArray();
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function test_virtual_punches_from_regularization_are_used_and_traced(): void
    {
        $sch = EngineFixtures::schedule();
        $punches = [
            new Punch('event:1', EngineFixtures::at('09:00'), 'IN'),
            new Punch('regularization:7', EngineFixtures::at('18:00'), 'OUT', 'web', 'regularization'),
        ];
        $r = EngineFixtures::run($sch, $punches);
        $this->assertSame('present', $r->status);
        $this->assertSame(['event:1', 'regularization:7'], $r->punchIdsUsed);
    }

    public function test_many_punches_are_handled_and_breaks_counted(): void
    {
        $r = EngineFixtures::day(['09:00', '11:00', '11:15', '13:00', '13:45', '18:00'], ['breaks' => ['mode' => 'punch']]);
        $this->assertCount(3, $r->pairs);
        $this->assertSame(60, $r->breakMinutes);
        $this->assertSame(480, $r->netMinutes);
    }
}
