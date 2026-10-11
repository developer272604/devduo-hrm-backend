<?php
namespace App\Domain\Operations;

use App\Domain\Access\Classification;
use App\Domain\Quality\{Finding, QualityRule};
use Illuminate\Support\Facades\DB;

/** Read-only data-quality rules for assets, expenses and travel (identifiers only; no amounts or private details). */
final class OperationsQualityRules {
    /** @return array<int,QualityRule> */
    public static function all(): array {
        return [
            self::rule('asset_held_by_departed_employee', 'Assets still assigned to people who have left', function (int $t) {
                foreach (DB::table('assets')->join('employees', 'employees.id', '=', 'assets.employee_id')->where('assets.tenant_id', $t)->whereIn('employees.status', ['separated', 'inactive', 'archived'])->where('assets.status', 'assigned')->get(['assets.id', 'assets.employee_id', 'assets.identifier']) as $a)
                    yield new Finding((int) $a->employee_id, 'asset-departed:'.$a->id, 'An asset is still assigned to someone who has left', 'Record the return of '.$a->identifier.'.', 'action_required', ['asset', (int) $a->id], [], Classification::HR_CONFIDENTIAL);
            }),
            self::rule('asset_missing_serial_number', 'Assets registered without a serial number', function (int $t) {
                foreach (DB::table('assets')->where('tenant_id', $t)->whereNull('serial_number')->whereNotIn('status', ['disposed', 'lost'])->get(['id', 'identifier']) as $a)
                    yield new Finding(null, 'asset-serial:'.$a->id, 'An asset has no serial number', 'Add a serial number for '.$a->identifier.' to support warranty and audit.', 'info', ['asset', (int) $a->id], [], Classification::MANAGER_SENSITIVE);
            }),
            self::rule('expense_claim_stuck_after_exit', 'Open expense claims for people who have left', function (int $t) {
                foreach (DB::table('expense_claims')->join('employees', 'employees.id', '=', 'expense_claims.employee_id')->where('expense_claims.tenant_id', $t)->whereIn('expense_claims.status', ['submitted', 'approved'])
                    ->whereIn('employees.status', ['separated', 'inactive', 'archived'])->get(['expense_claims.id', 'expense_claims.employee_id']) as $c)
                    yield new Finding((int) $c->employee_id, 'claim-departed:'.$c->id, 'An expense claim is still open for someone who has left', 'Review, approve or reject the claim, then confirm payment.', 'warning', ['expense_claim', (int) $c->id], [], Classification::HR_CONFIDENTIAL);
            }),
            self::rule('travel_trip_not_reconciled', 'Finished trips without a reconciliation', function (int $t) {
                foreach (DB::table('travel_requests')->where('tenant_id', $t)->where('status', 'approved')->where('ends_on', '<', now()->subDays(30)->toDateString())
                    ->whereNotIn('id', DB::table('travel_reconciliations')->where('tenant_id', $t)->select('travel_request_id'))->get(['id', 'employee_id']) as $r)
                    yield new Finding((int) $r->employee_id, 'trip-unreconciled:'.$r->id, 'A trip ended over 30 days ago and is not reconciled', 'Close the post-trip reconciliation.', 'warning', ['travel_request', (int) $r->id], [], Classification::MANAGER_SENSITIVE);
            }),
        ];
    }
    private static function rule(string $key, string $label, \Closure $scan): QualityRule {
        return new class($key, $label, $scan) implements QualityRule {
            public function __construct(private string $key, private string $label, private \Closure $scanner) {}
            public function key(): string { return $this->key; }
            public function category(): string { return 'operations'; }
            public function label(): string { return $this->label; }
            public function scan(int $tenantId): iterable { return ($this->scanner)($tenantId); }
        };
    }
}
