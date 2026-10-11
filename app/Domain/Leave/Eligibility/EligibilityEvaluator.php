<?php

namespace App\Domain\Leave\Eligibility;

use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Eligibility is policy DATA (`eligibility` block of a type rule and `leave_types.config`): probation, confirmation,
 * minimum tenure, notice-period behaviour. Nothing here knows about a country's law.
 *
 * Two layers:
 *  - applicability(): static scope (countries / legal entities / employee types) from the as-of-date org context. Failing it
 *    means the type is simply not granted to this employee.
 *  - evaluate(): time-based eligibility for one employee on a date, with a reason per failed check. Pure, never writes.
 */
final class EligibilityEvaluator
{
    /** @return ?string reason the type does not apply, or null when it applies */
    public static function applicability(array $context, array $typeConfig, array $eligibility): ?string
    {
        $countries = array_map('strtoupper', array_merge((array) ($typeConfig['countries'] ?? []), []));
        $ruleCountries = array_map('strtoupper', (array) ($eligibility['countries'] ?? []));
        foreach ([$countries, $ruleCountries] as $list) {
            if ($list && ! in_array(strtoupper((string) ($context['country'] ?? '')), $list, true)) {
                return 'Not applicable in the employee\'s country ('.($context['country'] ?? 'unknown').').';
            }
        }
        $checks = [['legal_entities', 'legal_entity', 'legal entity'], ['employee_types', 'employee_type', 'employee type']];
        foreach ($checks as [$key, $ctxKey, $label]) {
            foreach ([(array) ($typeConfig[$key] ?? []), (array) ($eligibility[$key] ?? [])] as $list) {
                if ($list && ! in_array((string) ($context[$ctxKey] ?? ''), array_map('strval', $list), true)) {
                    return 'Not applicable to the employee\'s '.$label.'.';
                }
            }
        }

        return null;
    }

    /** @return array{eligible:bool,eligible_from:?string,checks:array<int,array<string,mixed>>,reasons:array<int,string>} */
    public function evaluate(int $employeeId, array $eligibility, string $date): array
    {
        $employee = DB::table('employees')->where('tenant_id', app(TenantContext::class)->id)->where('id', $employeeId)->first();
        if (! $employee) {
            return ['eligible' => false, 'eligible_from' => null, 'checks' => [], 'reasons' => ['Employee not found.']];
        }
        $on = CarbonImmutable::parse($date);
        $joined = CarbonImmutable::parse($employee->joined_on);
        $checks = [];
        $reasons = [];
        $earliest = [];
        $record = function (string $name, bool $ok, string $detail, ?CarbonImmutable $from = null) use (&$checks, &$reasons, &$earliest) {
            $checks[] = ['check' => $name, 'passed' => $ok, 'detail' => $detail];
            if (! $ok) {
                $reasons[] = $detail;
                if ($from) {
                    $earliest[] = $from->toDateString();
                }
            }
        };

        if (isset($eligibility['probation_days']) && $eligibility['probation_days'] > 0) {
            $from = $joined->addDays((int) $eligibility['probation_days']);
            $record('probation', $on->gte($from), $on->gte($from) ? 'Probation waiting period of '.$eligibility['probation_days'].' days has passed.' : 'Eligible after a '.$eligibility['probation_days'].' day waiting period (from '.$from->toDateString().').', $from);
        }
        if (isset($eligibility['min_tenure_days']) && $eligibility['min_tenure_days'] > 0) {
            $from = $joined->addDays((int) $eligibility['min_tenure_days']);
            $record('min_tenure', $on->gte($from), $on->gte($from) ? 'Minimum tenure met.' : 'Requires '.$eligibility['min_tenure_days'].' days of service (from '.$from->toDateString().').', $from);
        }
        if (isset($eligibility['max_tenure_days'])) {
            $until = $joined->addDays((int) $eligibility['max_tenure_days']);
            $record('max_tenure', $on->lte($until), $on->lte($until) ? 'Within the tenure window.' : 'Only available during the first '.$eligibility['max_tenure_days'].' days of service.');
        }
        if (! empty($eligibility['requires_confirmation'])) {
            $confirmed = $employee->confirmation_date !== null && $employee->confirmation_date <= $date;
            $record('confirmation', $confirmed || ! $employee->probation_applicable, $confirmed || ! $employee->probation_applicable ? 'Confirmed or not on probation.' : 'Requires employment confirmation.',
                $employee->probation_end ? CarbonImmutable::parse($employee->probation_end) : null);
        }
        if (! empty($eligibility['block_during_notice'])) {
            $inNotice = $employee->separated_on !== null && $employee->separated_on >= $date;
            $record('notice_period', ! $inNotice, $inNotice ? 'Not available while serving notice (last working day '.$employee->separated_on.').' : 'Not serving notice.');
        }
        if (! empty($eligibility['worker_types'])) {
            $ok = in_array($employee->worker_type, (array) $eligibility['worker_types'], true);
            $record('worker_type', $ok, $ok ? 'Worker type is covered.' : 'Not available to worker type '.$employee->worker_type.'.');
        }
        sort($earliest);

        return ['eligible' => ! $reasons, 'eligible_from' => $earliest ? end($earliest) : null, 'checks' => $checks, 'reasons' => $reasons];
    }
}
