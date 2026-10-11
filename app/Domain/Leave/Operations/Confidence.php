<?php

namespace App\Domain\Leave\Operations;

use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Support\Units;
use Illuminate\Support\Facades\DB;

/**
 * Leave Confidence: a deterministic, explainable statement of how far leave numbers can be trusted. No score, no model.
 *
 *   confirmed        nothing open: ledger consistent, no negative beyond limit, no pending requests older than the review window
 *   pending          requests/adjustments are still waiting for a decision (numbers may still move)
 *   review_required  something is inconsistent or beyond its limit and a person must look (ledger mismatch, balance below its limit,
 *                    policy missing for an active account, request marked review_required)
 *
 * Every level carries the exact reasons (facts with counts), so the label is always traceable.
 */
final class Confidence
{
    /** Pending requests older than this many days are called out as stale. */
    public const STALE_DAYS = 7;

    public const LEVELS = [
        'confirmed' => ['label' => 'Confirmed', 'meaning' => 'Balances match the ledger and nothing is waiting for a decision.'],
        'pending' => ['label' => 'Pending', 'meaning' => 'Requests or adjustments are waiting for a decision, so balances may still change.'],
        'review_required' => ['label' => 'Review required', 'meaning' => 'Something is inconsistent or beyond its limit. A person needs to look at the reasons.'],
    ];

    public function __construct(private BalanceService $balances) {}

    public static function describe(string $level): array
    {
        return ['level' => $level] + (self::LEVELS[$level] ?? self::LEVELS['pending']);
    }

    /** @return array{level:string,label:string,meaning:string,reasons:array<int,array<string,mixed>>} */
    public function forEmployee(int $employeeId, ?string $asOf = null): array
    {
        $asOf ??= LeaveOps::today();
        $tenant = LeaveOps::tenantId();
        $reasons = [];
        $level = 'confirmed';
        $raise = function (string $to) use (&$level) {
            $rank = ['confirmed' => 0, 'pending' => 1, 'review_required' => 2];
            if ($rank[$to] > $rank[$level]) {
                $level = $to;
            }
        };
        foreach (LeaveAccount::query()->where('employee_id', $employeeId)->where('status', 'open')->get() as $account) {
            $code = DB::table('leave_types')->where('id', $account->leave_type_id)->value('code');
            $check = $this->balances->verify($account);
            if (! $check['consistent']) {
                $reasons[] = ['key' => 'ledger_mismatch', 'level' => 'review_required', 'leave_type' => $code, 'message' => 'The ledger total for '.$code.' does not match its recomputed value ('.$check['exact'].' vs '.$check['database'].').'];
                $raise('review_required');
            }
            $available = $this->balances->balance($account, $asOf)->available;
            $limit = Units::normalize($account->getRawOriginal('negative_limit'));
            if (Units::cmp($available, Units::neg($limit)) < 0) {
                $reasons[] = ['key' => 'below_limit', 'level' => 'review_required', 'leave_type' => $code, 'message' => 'The '.$code.' balance ('.$available.') is below its allowed minimum of -'.$limit.'.'];
                $raise('review_required');
            }
        }
        $pending = DB::table('leave_requests')->where('tenant_id', $tenant)->where('employee_id', $employeeId)->where('status', 'pending')->get(['submitted_at', 'created_at']);
        if ($pending->isNotEmpty()) {
            $stale = $pending->filter(fn ($r) => ($r->submitted_at ?? $r->created_at) && substr((string) ($r->submitted_at ?? $r->created_at), 0, 10) < date('Y-m-d', strtotime($asOf.' -'.self::STALE_DAYS.' days')))->count();
            $reasons[] = ['key' => 'pending_requests', 'level' => 'pending', 'count' => $pending->count(), 'message' => $pending->count().' leave request(s) are waiting for a decision.'.($stale ? ' '.$stale.' have waited more than '.self::STALE_DAYS.' days.' : '')];
            $raise('pending');
        }
        $flagged = DB::table('leave_requests')->where('tenant_id', $tenant)->where('employee_id', $employeeId)->whereIn('status', ['pending', 'approved'])->where('confidence', 'review_required')->where('end_date', '>=', $asOf)->count();
        if ($flagged > 0) {
            $reasons[] = ['key' => 'request_review', 'level' => 'review_required', 'count' => $flagged, 'message' => $flagged.' upcoming request(s) are marked for review.'];
            $raise('review_required');
        }
        $adjustments = DB::table('leave_adjustments')->where('tenant_id', $tenant)->where('employee_id', $employeeId)->where('status', 'pending')->count();
        if ($adjustments > 0) {
            $reasons[] = ['key' => 'pending_adjustments', 'level' => 'pending', 'count' => $adjustments, 'message' => $adjustments.' balance adjustment(s) are waiting for approval.'];
            $raise('pending');
        }

        return self::describe($level) + ['reasons' => $reasons];
    }

    /** Distribution over many employees (command center). @param array<int,int> $employeeIds @return array<string,int> */
    public function summary(array $employeeIds, int $limit = 500): array
    {
        $out = ['confirmed' => 0, 'pending' => 0, 'review_required' => 0, 'sampled' => 0];
        foreach (array_slice($employeeIds, 0, $limit) as $id) {
            $out[$this->forEmployee($id)['level']]++;
            $out['sampled']++;
        }

        return $out;
    }
}
