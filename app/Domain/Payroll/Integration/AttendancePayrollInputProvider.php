<?php

namespace App\Domain\Payroll\Integration;

use App\Domain\Attendance\Operations\PayrollReadiness;
use App\Domain\Payroll\Contracts\Dto\PayrollInputBatch;
use App\Domain\Payroll\Contracts\Dto\PayrollInputLine;
use App\Domain\Payroll\Contracts\Dto\PayrollInputQuery;
use App\Domain\Payroll\Contracts\PayrollInputProvider;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 3 -> Phase 5 adapter. Reads ONLY the finalized attendance payroll output (PayrollReadiness::output, the service behind
 * GET /api/v1/attendance/payroll-output) and normalizes it into PayrollInputLines. Nothing is recalculated here.
 *
 * Seam note: the Phase 3 output exposes day counts as rounded floats (2 dp). They are converted with number_format to decimal STRINGS at the
 * boundary and never used in arithmetic as floats. Open (non-final) data is only included when the query asks for it and is then flagged
 * is_final=false. The data is scoped by the actor's attendance permissions (attendance.manage|process|reports.view + data scope).
 */
final class AttendancePayrollInputProvider implements PayrollInputProvider
{
    public const PAGE_SIZE = 100;

    public function __construct(private PayrollReadiness $readiness) {}

    public function source(): string
    {
        return 'attendance';
    }

    public function collect(PayrollInputQuery $query): PayrollInputBatch
    {
        if ($query->actor === null) {
            throw new InvalidArgumentException('Attendance payroll inputs are scope-checked: an actor is required.');
        }
        $only = $query->employeeIds === null ? null : DB::table('employees')->whereIn('id', $query->employeeIds ?: [0])->pluck('uuid')->all();
        $lines = [];
        $warnings = [];
        $complete = true;
        $page = 1;
        do {
            $out = $this->readiness->output($query->actor, $query->groupCode, $query->from, $query->to, $query->includeOpen, $page, self::PAGE_SIZE);
            foreach ($out['data'] as $row) {
                if ($only !== null && ! in_array($row['employee_uuid'], $only, true)) {
                    continue;
                }
                array_push($lines, ...self::linesFor($row, $query, $out['data_status']));
                if (! $row['complete']) {
                    $complete = false;
                }
            }
            $last = (int) $out['meta']['last_page'];
            $page++;
        } while ($page <= $last);

        if (! $complete) {
            $warnings[] = ['key' => 'attendance_not_final', 'count' => count(array_filter($lines, fn (PayrollInputLine $l) => ! $l->isFinal)) ?: 1, 'message' => 'Some employees still have unprocessed or non-final attendance in this period.'];
        }

        return new PayrollInputBatch('attendance', $query->from, $query->to, $lines, $warnings, $complete, 'attendance:'.($query->groupCode ?? '*').':'.$query->from.':'.$query->to);
    }

    /**
     * One attendance payroll-output row -> normalized lines. Public so it can be tested without attendance fixtures.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,PayrollInputLine>
     */
    public static function linesFor(array $row, PayrollInputQuery $query, string $dataStatus = 'FINALIZED DATA ONLY'): array
    {
        $final = (bool) ($row['complete'] ?? false) && ! ($row['includes_open_days'] ?? false);
        $ref = 'attendance:'.($query->groupCode ?? '*').':'.$query->from.':'.$query->to;
        $meta = ['data_status' => $dataStatus, 'confidence' => $row['confidence']['code'] ?? ($row['confidence'] ?? null), 'excluded_open_days' => $row['excluded_open_days'] ?? 0, 'unprocessed_days' => $row['unprocessed_days'] ?? 0];
        $make = fn (string $source, string $kind, mixed $value, string $unit) => new PayrollInputLine($source, $kind, (string) $row['employee_uuid'], $row['employee_number'] ?? null, self::decimal($value, $unit === 'days' ? 2 : 0), $unit, $ref, $final, false, $meta);

        return [
            $make('attendance', 'payable_days', $row['payable_days'] ?? 0, 'days'),
            $make('attendance', 'present_days', $row['present_days'] ?? 0, 'days'),
            $make('attendance', 'absent_days', $row['absent_days'] ?? 0, 'days'),
            $make('attendance', 'holiday_days', $row['holiday_days'] ?? 0, 'days'),
            $make('attendance', 'weekly_off_days', $row['weekly_off_days'] ?? 0, 'days'),
            $make('overtime', 'approved_overtime_minutes', $row['approved_overtime_minutes'] ?? 0, 'minutes'),
        ];
    }

    /** Boundary conversion of the Phase 3 numbers to a decimal string (never kept as float). */
    public static function decimal(mixed $value, int $scale): string
    {
        if (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            return bcadd($value, '0', $scale);
        }

        return number_format((float) $value, $scale, '.', '');
    }
}
