<?php

namespace App\Domain\Migration;

use Illuminate\Support\Facades\DB;

/**
 * Stage catalogue for moving from another HRMS. Each stage wraps an import that ALREADY exists in its own module (mapping, validation,
 * commit and row-level errors stay there); Migration Studio only orders them, enforces dependencies and proves the result.
 */
final class MigrationCatalog
{
    public const STAGES = [
        'employees' => ['label' => 'Employees and organization', 'table' => 'employee_imports', 'kind' => null, 'done' => ['completed', 'partial'], 'depends' => [],
            'columns' => ['total' => 'total_rows', 'ok' => 'imported_rows', 'error' => 'error_rows'], 'where' => 'People > Imports', 'href' => '/people/imports',
            'metrics' => ['rows_imported', 'headcount_active', 'headcount_total']],
        'leave_opening_balances' => ['label' => 'Leave opening balances', 'table' => 'leave_imports', 'kind' => 'opening_balance', 'done' => ['completed'], 'depends' => ['employees'],
            'columns' => ['total' => 'total_rows', 'ok' => 'imported_rows', 'error' => 'error_rows'], 'where' => 'Leave > Imports', 'href' => '/leave/hr',
            'metrics' => ['rows_imported']],
        'leave_history' => ['label' => 'Historical leave requests', 'table' => 'leave_imports', 'kind' => 'historical_requests', 'done' => ['completed'], 'depends' => ['employees', 'leave_opening_balances'],
            'columns' => ['total' => 'total_rows', 'ok' => 'imported_rows', 'error' => 'error_rows'], 'where' => 'Leave > Imports', 'href' => '/leave/hr',
            'metrics' => ['rows_imported']],
        'compensation' => ['label' => 'Compensation', 'table' => 'payroll_imports', 'kind' => 'compensation', 'done' => ['committed'], 'depends' => ['employees'],
            'columns' => ['total' => 'rows_total', 'ok' => 'rows_ok', 'error' => 'rows_error'], 'where' => 'Payroll > Compensation import', 'href' => '/payroll',
            'metrics' => ['rows_imported']],
    ];

    public const METRICS = [
        'rows_imported' => 'Rows imported by the linked import',
        'headcount_active' => 'Employees with status active in Devduo',
        'headcount_total' => 'All employee records in Devduo',
    ];

    public static function order(): array
    {
        return array_keys(self::STAGES);
    }

    /** Current value of a reconciliation metric, measured from the live data, never typed in. */
    public static function measure(int $tenantId, string $metric, ?array $snapshot): ?float
    {
        return match ($metric) {
            'rows_imported' => $snapshot !== null ? (float) $snapshot['ok'] : null,
            'headcount_active' => (float) DB::table('employees')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('status', 'active')->count(),
            'headcount_total' => (float) DB::table('employees')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count(),
            default => null,
        };
    }
}
