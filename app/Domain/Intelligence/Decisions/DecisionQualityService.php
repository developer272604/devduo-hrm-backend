<?php

namespace App\Domain\Intelligence\Decisions;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manager Decision Quality: how quickly and how consistently people decide the requests that reach them (leave, overtime, expense claims).
 * It measures response behaviour only: turnaround time, how many waited longer than a service target, and the share approved. It is NOT a
 * performance rating and says nothing about whether a decision was right. A person's row appears only after a minimum number of decisions, a
 * company-wide viewer sees everyone, and anyone else sees only their own numbers.
 */
final class DecisionQualityService
{
    public const MIN_DECISIONS = 10;

    public const MAX_DAYS = 180;

    private const SOURCES = [
        'leave' => ['table' => 'leave_requests', 'submitted' => 'submitted_at', 'decided' => 'decided_at', 'by' => 'decided_by', 'approved' => ['approved'], 'rejected' => ['rejected']],
        'overtime' => ['table' => 'overtime_requests', 'submitted' => 'created_at', 'decided' => 'decided_at', 'by' => 'decided_by', 'approved' => ['approved', 'partially_approved'], 'rejected' => ['rejected']],
        'expenses' => ['table' => 'expense_claims', 'submitted' => 'submitted_at', 'decided' => 'reviewed_at', 'by' => 'reviewed_by', 'approved' => ['approved', 'paid'], 'rejected' => ['rejected']],
    ];

    public function __construct(private DataScope $scope) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function report(User $u, string $from, string $to, int $targetHours = 48): array
    {
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                $this->fail($k, 'Use a valid date (YYYY-MM-DD).');
            }
        }
        if ($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
            $this->fail('to', 'Choose a period of 1 to '.self::MAX_DAYS.' days.');
        }
        if ($targetHours < 1 || $targetHours > 720) {
            $this->fail('target_hours', 'Choose a service target from 1 to 720 hours.');
        }
        $all = $this->scope->hasTenantWide($u, 'intelligence.decisions.view');
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        $people = []; // user id => [source => list of [hours, approved]]
        foreach (self::SOURCES as $key => $s) {
            $q = DB::table($s['table'])->where('tenant_id', $u->tenant_id)->whereNotNull($s['by'])->whereNotNull($s['decided'])->whereNotNull($s['submitted'])->whereBetween($s['decided'], [$start, $end])
                ->whereIn('status', [...$s['approved'], ...$s['rejected']]);
            if (! $all) {
                $q->where($s['by'], $u->id);
            }
            foreach ($q->get([$s['by'].' as decider', $s['submitted'].' as submitted', $s['decided'].' as decided', 'status']) as $r) {
                $hours = max(0, (CarbonImmutable::parse($r->decided)->getTimestamp() - CarbonImmutable::parse($r->submitted)->getTimestamp()) / 3600);
                $people[$r->decider][$key][] = ['hours' => $hours, 'approved' => in_array($r->status, $s['approved'], true)];
            }
        }
        $names = User::whereIn('id', array_keys($people))->pluck('name', 'id');
        $rows = [];
        $withheld = 0;
        foreach ($people as $uid => $bySource) {
            $flat = array_merge(...array_values($bySource));
            if (count($flat) < self::MIN_DECISIONS) {
                $withheld++;

                continue;
            }
            $hours = array_column($flat, 'hours');
            sort($hours);
            $mid = intdiv(count($hours), 2);
            $median = count($hours) % 2 ? $hours[$mid] : ($hours[$mid - 1] + $hours[$mid]) / 2;
            $rows[] = ['person' => ['id' => $uid, 'name' => $names[$uid] ?? 'Unknown', 'is_you' => $uid === $u->id], 'decisions' => count($flat), 'median_hours' => round($median, 1),
                'slower_than_target_pct' => round(count(array_filter($hours, fn ($h) => $h > $targetHours)) / count($hours) * 100, 1), 'approved_pct' => round(count(array_filter($flat, fn ($r) => $r['approved'])) / count($flat) * 100, 1),
                'by_source' => collect($bySource)->map(fn ($items) => ['decisions' => count($items), 'approved_pct' => round(count(array_filter($items, fn ($r) => $r['approved'])) / count($items) * 100, 1)])->all()];
        }
        usort($rows, fn ($a, $b) => $b['decisions'] <=> $a['decisions']);
        Audit::record('decisions.report', 'intelligence', 'user:'.$u->id, ['from' => $from, 'to' => $to, 'scope' => $all ? 'company' : 'self']);

        return ['period' => ['from' => $from, 'to' => $to], 'target_hours' => $targetHours, 'scope' => $all ? 'company' : 'own', 'minimum_decisions' => self::MIN_DECISIONS, 'rows' => $rows, 'withheld_people' => $all ? $withheld : null,
            'definitions' => ['median_hours' => 'Middle value of hours from submission to decision.', 'slower_than_target_pct' => "Share of decisions that took longer than the target of {$targetHours} hours.", 'approved_pct' => 'Approved (or partly approved) divided by approved plus rejected. A high or low share is not good or bad by itself.'],
            'limitations' => 'This shows response behaviour only. It is not a performance rating and does not judge whether decisions were right. Waiting time includes weekends, holidays and any time the approver was away. People with fewer than '.self::MIN_DECISIONS.' decisions in the period are not shown. Only completed decisions with recorded times are counted.'];
    }
}
