<?php

namespace App\Domain\Payroll\Runs;

use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollPeriodTransition;
use App\Domain\Payroll\Support\PayrollStates;

/** Moves a payroll period along the legal state machine (shortest path) as its regular run advances. Best effort: no legal path = unchanged. */
final class PeriodSync
{
    public const RUN_TO_PERIOD = ['calculating' => 'processing', 'review' => 'validation', 'approval' => 'approval', 'approved' => 'approved', 'locked' => 'locked', 'payment' => 'payment_processing', 'closed' => 'paid', 'reopened' => 'reopened'];

    public static function follow(PayrollPeriod $period, string $runStatus, ?int $actorId, ?string $reason = null): void
    {
        $target = self::RUN_TO_PERIOD[$runStatus] ?? null;
        if (! $target || $period->state === $target) {
            return;
        }
        $path = self::path($period->state, $target);
        if (! $path) {
            return;
        }
        foreach ($path as $next) {
            PayrollPeriodTransition::create(['period_id' => $period->id, 'from_state' => $period->state, 'to_state' => $next, 'actor_id' => $actorId, 'reason' => $reason, 'meta' => ['source' => 'run_engine']]);
            $period->update(['state' => $next]);
        }
    }

    /** @return array<int,string> */
    private static function path(string $from, string $to): array
    {
        $queue = [[$from, []]];
        $seen = [$from => true];
        while ($queue) {
            [$state, $trail] = array_shift($queue);
            foreach (PayrollStates::PERIOD_TRANSITIONS[$state] ?? [] as $next) {
                if (isset($seen[$next])) {
                    continue;
                }
                $seen[$next] = true;
                if ($next === $to) {
                    return [...$trail, $next];
                }
                $queue[] = [$next, [...$trail, $next]];
            }
        }

        return [];
    }
}
