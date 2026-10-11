<?php
namespace App\Domain\Expenses;
use App\Domain\Expenses\Models\ExpenseClaim;
use App\Domain\Lifecycle\Contracts\ClearanceProvider;
final class ExpenseClearanceProvider implements ClearanceProvider {
    public function key(): string { return 'expenses'; }
    public function blockers(int $employeeId): array {
        $count = ExpenseClaim::where('employee_id', $employeeId)->whereIn('status', ['submitted', 'approved'])->count();
        return $count ? ["{$count} expense claim(s) await review or confirmed reimbursement."] : [];
    }
}
