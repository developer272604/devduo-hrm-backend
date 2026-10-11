<?php

namespace App\Domain\Analytics;

/**
 * Governance text for every catalog metric: definition, source tables, grain and suppression rule.
 * Shown by the catalog, by the explain endpoint and next to every number in the UI.
 */
final class MetricDocs
{
    private const K = 'Cells with fewer than 5 contributing employments are withheld (shown as suppressed, never as zero).';

    public const DOCS = [
        'headcount' => ['definition' => 'Number of employments in a working status on the last day of the period.', 'tables' => ['employees', 'employee_status_history', 'employee_assignments'], 'grain' => 'One employment (not a person, not FTE) as of the period end date.', 'suppression' => self::K.' Breakdowns are withheld entirely when any cell is small.'],
        'hires' => ['definition' => 'Employments whose joining date falls in the inclusive period.', 'tables' => ['employees'], 'grain' => 'One employment per joining date.', 'suppression' => 'Nonzero counts below 5 are withheld.'],
        'leavers' => ['definition' => 'Employments whose separation date falls in the inclusive period.', 'tables' => ['employees'], 'grain' => 'One employment per separation date.', 'suppression' => 'Nonzero counts below 5 are withheld.'],
        'attrition' => ['definition' => 'Leavers as a percentage of average headcount (opening and closing). Not annualized.', 'tables' => ['employees', 'employee_status_history'], 'grain' => 'Employment, period level.', 'suppression' => 'Withheld when leavers, opening or closing headcount is nonzero but below 5.'],
        'tenure' => ['definition' => 'Mean days since joining for employments working at period end.', 'tables' => ['employees', 'employee_status_history'], 'grain' => 'Employment as of period end.', 'suppression' => self::K],
        'retention_cohort' => ['definition' => 'Of the employments that joined in the period (the hire cohort), the percentage still in a working status at the period end.', 'tables' => ['employees', 'employee_status_history'], 'grain' => 'Hire cohort = employments joined within the period; measured at the period end date.', 'suppression' => 'Withheld when the cohort has fewer than 5 employments or when the number who left is nonzero but below 5.'],
        'overtime' => ['definition' => 'Approved overtime minutes on finalized attendance days.', 'tables' => ['attendance_days'], 'grain' => 'One attendance day per employment.', 'suppression' => self::K],
        'absence' => ['definition' => 'Absent days as a percentage of scheduled days (absenteeism rate) on finalized attendance days.', 'tables' => ['attendance_days'], 'grain' => 'One scheduled attendance day per employment.', 'suppression' => 'Withheld when fewer than 5 employments contributed or absences came from fewer than 5 employments.'],
        'learning_completions' => ['definition' => 'Course enrolments completed within the period (workspace timezone).', 'tables' => ['learning_enrolments'], 'grain' => 'One completed enrolment.', 'suppression' => self::K],
        'training_compliance' => ['definition' => 'Mandatory-training assignments due in the period that are completed, as a percentage of assignments that are completed or still open. Waived assignments are excluded.', 'tables' => ['learning_assignments'], 'grain' => 'One mandatory assignment per employment and cycle.', 'suppression' => 'Withheld when fewer than 5 employments are assigned or when 1 to 4 employments are non-compliant.'],
        'leave_utilization' => ['definition' => 'Leave units consumed (posted consumption entries) in the period.', 'tables' => ['leave_ledger_entries'], 'grain' => 'One ledger entry.', 'suppression' => self::K],
        'leave_liability' => ['definition' => 'Unused paid-leave balance in days as of the period end, summed over employments. Per-employment negative balances count as zero. Decimal-safe (4 places).', 'tables' => ['leave_ledger_entries', 'leave_types'], 'grain' => 'Employment and leave type balance as of the period end date; reservations excluded.', 'suppression' => self::K],
        'recruitment_pipeline' => ['definition' => 'Applications that entered each stage during the period.', 'tables' => ['recruitment_applications'], 'grain' => 'One application, current stage.', 'suppression' => 'The whole result is withheld when any stage count is below 5. No candidate identity is returned.'],
        'time_to_hire' => ['definition' => 'Mean days from application creation to an accepted offer response, for offers accepted in the period.', 'tables' => ['recruitment_offers', 'recruitment_applications'], 'grain' => 'One accepted offer.', 'suppression' => 'Withheld when fewer than 5 offers were accepted in the period.'],
        'offer_accept_rate' => ['definition' => 'Accepted offers as a percentage of offers that were accepted or declined in the period.', 'tables' => ['recruitment_offers'], 'grain' => 'One answered offer (latest status).', 'suppression' => 'Withheld when fewer than 5 offers were answered or when accepted or declined is nonzero but below 5.'],
        'performance_status' => ['definition' => 'Reviews finalized within the period.', 'tables' => ['performance_reviews'], 'grain' => 'One finalized review.', 'suppression' => self::K],
        'rating_distribution' => ['definition' => 'Number of finalized reviews per final rating label in the period.', 'tables' => ['performance_reviews'], 'grain' => 'One finalized review per employment and cycle.', 'suppression' => 'The whole distribution is withheld when fewer than 5 reviews exist or any rating bucket has fewer than 5.'],
        'ticket_sla' => ['definition' => 'Resolved tickets answered within SLA as a percentage of resolved tickets with an SLA.', 'tables' => ['experience_tickets'], 'grain' => 'One resolved ticket.', 'suppression' => 'Withheld when on-time or late tickets come from 1 to 4 requesters.'],
        'payroll_totals' => ['definition' => 'Gross and net totals by currency for locked or closed payroll runs in the period, with change against the prior equal-length period.', 'tables' => ['payroll_calculation_results', 'payroll_runs', 'payroll_periods'], 'grain' => 'One current calculation result per employment and period, by currency.', 'suppression' => 'Withheld when fewer than 5 employments are included (also for the prior period). Currencies are never combined.'],
        'payroll_cost_trend' => ['definition' => 'Gross and net payroll by payroll period end date and currency, optionally by department (assignment effective at the report end date). Amounts are decimal strings.', 'tables' => ['payroll_calculation_results', 'payroll_runs', 'payroll_periods', 'employee_assignments'], 'grain' => 'One current calculation result per employment, grouped by period end date and currency.', 'suppression' => 'Each period cell with fewer than 5 employments is withheld; a department breakdown is withheld entirely when any cell is small. Requires payroll reporting permission.'],
    ];

    /** Metrics that may appear on an employee-safe board (no pay, review or person-level data). */
    public const EMPLOYEE_SAFE = ['headcount', 'training_compliance', 'learning_completions', 'ticket_sla'];

    /** Metrics never allowed on manager or employee boards. */
    public const HR_ONLY = ['payroll_totals', 'payroll_cost_trend', 'recruitment_pipeline', 'time_to_hire', 'offer_accept_rate'];

    public static function explain(string $key): array
    {
        $d = self::DOCS[$key] ?? ['definition' => '', 'tables' => [], 'grain' => '', 'suppression' => ''];
        $c = AnalyticsService::CATALOG[$key] ?? [];

        return ['metric' => $key, 'label' => $c['label'] ?? $key, 'definition' => $d['definition'], 'formula' => $c['formula'] ?? '', 'source_tables' => $d['tables'], 'grain' => $d['grain'], 'suppression_rule' => $d['suppression'], 'unit' => $c['unit'] ?? null];
    }
}
