<?php

namespace Tests\Feature\Intelligence;

use App\Domain\People\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

/**
 * Synthetic tenant with KNOWN injected anomalies and realistic noise, used to measure the detector's precision and recall.
 * This validates the detection LOGIC and surfaces rule bugs. It says nothing about real-world rates: that needs real tenant data.
 * The measured report is written to storage/app/cost-leakage-simulation.json for the docs.
 */
class CostLeakageSimulationTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const API = '/api/v1/intelligence/leakage';

    private const PEOPLE = 40;

    /** @var array<int,Employee> */
    private array $staff = [];

    private int $policy;

    /** rule => list of employee ids (or 'x:<merchant>' for cross-employee) that SHOULD be flagged */
    private array $truth = [];

    /** name => [employee id, rule]: legitimate cases we expect the rules may still flag */
    private array $weak = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant(); // 2026-10-07
        for ($i = 0; $i < self::PEOPLE; $i++) {
            $this->staff[$i] = $this->hire('Staff'.$i.' Person'.$i);
        }
        $this->signIn($this->admin);
        $this->policy = DB::table('expense_policies')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'name' => 'General', 'category' => 'meals', 'currency' => 'INR',
            'minor_units' => 2, 'max_amount_minor' => 99999999, 'receipt_threshold_minor' => 0, 'effective_from' => '2026-01-01', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        mt_srand(272604);
    }

    private function days(int $e, string $from, string $to, callable $minutes): void
    {
        $rows = [];
        for ($d = CarbonImmutable::parse($from); $d->lte(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            [$calc, $approved] = $minutes($d);
            $rows[] = ['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'employee_id' => $e, 'attendance_date' => $d->toDateString(), 'status' => 'present', 'overtime_minutes' => $calc,
                'approved_overtime_minutes' => $approved, 'engine_version' => 'sim', 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('attendance_days')->insert($chunk);
        }
    }

    private function claim(int $employee, string $merchant, string $date, int $amountMinor, string $status = 'approved'): void
    {
        $id = DB::table('expense_claims')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'employee_id' => $employee, 'created_by' => $this->admin->id, 'policy_id' => $this->policy,
            'policy_snapshot' => '{}', 'title' => 'Claim', 'currency' => 'INR', 'minor_units' => 2, 'total_minor' => $amountMinor, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('expense_lines')->insert(['tenant_id' => $this->admin->tenant_id, 'claim_id' => $id, 'spent_on' => $date, 'merchant' => $merchant, 'description' => 'x', 'amount_minor' => $amountMinor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function regularize(int $employee, string $date): void
    {
        DB::table('attendance_regularizations')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'employee_id' => $employee, 'attendance_date' => $date, 'type' => 'forgot_out',
            'reason' => 'Forgot', 'status' => 'approved', 'submitted_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function adjust(int $employee, string $kind, float $amount, string $status = 'applied'): void
    {
        DB::table('payroll_adjustments')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'employee_id' => $employee, 'kind' => $kind, 'status' => $status, 'amount' => $amount,
            'currency' => 'INR', 'reason' => 'Correction', 'created_by' => $this->admin->id, 'created_at' => now()->subDays(2), 'updated_at' => now()]);
    }

    /** 3 months of history for everyone, with realistic noise. Returns nothing; sets $this->truth. */
    private function build(): void
    {
        $ids = array_map(fn ($e) => $e->id, $this->staff);
        // Habitual heavy overtime workers (shift industry): consistent every month, so NOT anomalies.
        $habitual = array_slice($ids, 0, 6);
        $spike = array_slice($ids, 6, 3);        // ot_spike truth
        $excess = array_slice($ids, 9, 2);       // ot_over_calculated truth
        $regot = array_slice($ids, 11, 2);       // regularization_overtime truth
        $dupes = array_slice($ids, 13, 3);       // duplicate_expense truth (same employee)
        $adj = array_slice($ids, 16, 2);         // repeat_payroll_adjustment truth
        $this->truth = ['ot_spike' => $spike, 'ot_over_calculated' => $excess, 'regularization_overtime' => $regot, 'duplicate_expense' => $dupes, 'repeat_payroll_adjustment' => $adj];

        foreach ($ids as $e) {
            // Baseline: Jul-Sep. Habitual workers ~14 h/month; others 0-3 h/month with day-to-day noise.
            $monthly = in_array($e, $habitual, true) ? 840 : mt_rand(0, 180);
            $perDay = fn () => $monthly > 0 ? max(0, (int) round($monthly / 21 + mt_rand(-10, 10))) : 0;
            $this->days($e, '2026-07-01', '2026-09-30', function ($d) use ($perDay) { $m = $perDay(); return [$m, $m]; });
            // October (current month, 1-7th): normal behaviour for everyone first.
            $this->days($e, '2026-10-01', '2026-10-07', function ($d) use ($perDay) { $m = $perDay(); return [$m, $m]; });
        }
        // Inject spikes: replace Oct days with 4x baseline for spike group (over a full month the threshold needs 600+ min).
        foreach ($spike as $e) {
            DB::table('attendance_days')->where('employee_id', $e)->where('attendance_date', '>=', '2026-10-01')->update(['overtime_minutes' => 150, 'approved_overtime_minutes' => 150]);
        }
        foreach ($excess as $e) {
            DB::table('attendance_days')->where('employee_id', $e)->where('attendance_date', '>=', '2026-10-01')->update(['overtime_minutes' => 20, 'approved_overtime_minutes' => 90]);
        }
        foreach ($regot as $e) {
            DB::table('attendance_days')->where('employee_id', $e)->where('attendance_date', '>=', '2026-10-01')->update(['overtime_minutes' => 80, 'approved_overtime_minutes' => 80]);
            foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06'] as $d) {
                $this->regularize($e, $d);
            }
        }
        // Normal people regularize occasionally (1-2 times) with ordinary overtime: must NOT be flagged.
        foreach (array_slice($ids, 20, 8) as $e) {
            $this->regularize($e, '2026-10-02');
            $this->regularize($e, '2026-10-05');
        }
        // Expenses: everyone has a few ordinary, distinct claims.
        foreach ($ids as $i => $e) {
            foreach ([['Taxi', '2026-10-01', 18000], ['Lunch', '2026-10-02', 32000], ['Hotel', '2026-09-20', 450000]] as [$m, $d, $a]) {
                $this->claim($e, $m.' '.($i % 5), $d, $a + mt_rand(0, 9) * 100);
            }
        }
        foreach ($dupes as $e) {
            $this->claim($e, 'Grand Hotel', '2026-10-03', 520000);
            $this->claim($e, 'grand hotel ', '2026-10-03', 520000);
        }
        // Realistic noise: two colleagues genuinely share a team lunch bill and each claim half (same merchant/date/amount, different people).
        $this->claim($ids[30], 'Team Lunch Cafe', '2026-10-04', 62500);
        $this->claim($ids[31], 'Team Lunch Cafe', '2026-10-04', 62500);
        // Adjustments: ordinary single corrections and legitimate arrears are fine.
        foreach (array_slice($ids, 24, 10) as $e) {
            $this->adjust($e, 'correction', 1500);
        }
        foreach (array_slice($ids, 24, 4) as $e) {
            $this->adjust($e, 'arrear', 9000);
            $this->adjust($e, 'arrear', 9000);
            $this->adjust($e, 'arrear', 9000);
        }
        foreach ($adj as $e) {
            foreach ([4000, 6500, 2500] as $amt) {
                $this->adjust($e, 'manual_net', $amt);
            }
        }
        // KNOWN WEAK SPOTS: legitimate behaviour the rules cannot tell apart from leakage. Kept separate from the truth set on purpose.
        $this->claim($ids[35], 'City Cabs', '2026-10-05', 21000); // two real rides, same fare, same day
        $this->claim($ids[35], 'City Cabs', '2026-10-05', 21000);
        DB::table('attendance_days')->where('employee_id', $ids[36])->where('attendance_date', '>=', '2026-10-01')->update(['overtime_minutes' => 150, 'approved_overtime_minutes' => 150]); // genuine new project, no history
        $this->weak = ['same_day_same_fare_rides' => [$ids[35], 'duplicate_expense'], 'new_project_overtime_without_history' => [$ids[36], 'ot_spike']];
    }

    public function test_measure_detector_quality_on_a_synthetic_tenant_with_known_anomalies(): void
    {
        $this->build();
        $scan = $this->postJson(self::API.'/scan', ['from' => '2026-10-01', 'to' => '2026-10-07'])->assertOk();
        $cases = $this->getJson(self::API.'?status=all')->assertOk()->json('items');

        // Map detected cases to employee ids via evidence uuid.
        $uuidToId = DB::table('employees')->pluck('id', 'uuid');
        $detected = [];
        foreach ($cases as $c) {
            $emp = $c['evidence']['employee']['uuid'] ?? null;
            $ids = $emp ? [$uuidToId[$emp]] : collect($c['evidence']['claims'] ?? [])->map(fn ($x) => $uuidToId[$x['employee_uuid']])->unique()->all();
            $detected[$c['rule_code']][] = $ids;
        }
        $report = ['people' => self::PEOPLE, 'window' => '2026-10-01..2026-10-07', 'rules' => []];
        $totalTp = $totalFp = $totalFn = 0;
        foreach (['ot_spike', 'ot_over_calculated', 'regularization_overtime', 'duplicate_expense', 'repeat_payroll_adjustment'] as $rule) {
            $truth = $this->truth[$rule];
            $flaggedEmployees = collect($detected[$rule] ?? [])->flatten()->unique()->values()->all();
            $tp = count(array_intersect($truth, $flaggedEmployees));
            // Cross-employee duplicate (shared team lunch) is a false positive by construction; so is any flagged employee outside truth.
            $fp = count(array_diff($flaggedEmployees, $truth));
            $fn = count(array_diff($truth, $flaggedEmployees));
            $report['rules'][$rule] = ['injected' => count($truth), 'detected_cases' => count($detected[$rule] ?? []), 'true_positives' => $tp, 'false_positive_people' => $fp, 'missed' => $fn,
                'precision' => $tp + $fp ? round($tp / ($tp + $fp), 2) : null, 'recall' => count($truth) ? round($tp / count($truth), 2) : null];
            $totalTp += $tp;
            $totalFp += $fp;
            $totalFn += $fn;
        }
        $report['overall'] = ['true_positives' => $totalTp, 'false_positive_people' => $totalFp, 'missed' => $totalFn, 'precision' => round($totalTp / max(1, $totalTp + $totalFp), 2), 'recall' => round($totalTp / max(1, $totalTp + $totalFn), 2),
            'cases_raised' => count($cases), 'people_in_scope' => self::PEOPLE];
        foreach ($this->weak as $name => [$emp, $rule]) {
            $report['known_weak_spots'][$name] = ['rule' => $rule, 'flagged' => in_array($emp, collect($detected[$rule] ?? [])->flatten()->all(), true)];
        }
        file_put_contents(storage_path('app/cost-leakage-simulation.json'), json_encode($report, JSON_PRETTY_PRINT));
        fwrite(STDERR, "\n".json_encode($report, JSON_PRETTY_PRINT)."\n");

        // Hard guarantees: every injected anomaly is found; habitual overtime workers are not flagged as spikes.
        $this->assertSame(0, $totalFn, 'Missed injected anomalies: '.json_encode($report['rules']));
        $habitual = array_slice(array_map(fn ($e) => $e->id, $this->staff), 0, 6);
        $this->assertSame([], array_values(array_intersect($habitual, collect($detected['ot_spike'] ?? [])->flatten()->all())), 'Consistent heavy overtime must not be flagged as a spike');
        $this->assertGreaterThanOrEqual(0.7, $report['overall']['precision']);
    }
}
