<?php

namespace App\Domain\Payroll\Support;

use Illuminate\Validation\ValidationException;

/**
 * Controlled state machines and shared vocabularies. Every transition goes through assertTransition() and is audited by the owner
 * (period transitions are also persisted in payroll_period_transitions).
 */
final class PayrollStates
{
    public const PERIOD = ['upcoming', 'input_open', 'processing', 'validation', 'approval', 'approved', 'locked', 'payment_processing', 'paid', 'closed', 'reopened'];

    /** from => allowed targets. `reopened` re-enters the flow at input_open / processing / validation. */
    public const PERIOD_TRANSITIONS = [
        'upcoming' => ['input_open'],
        'input_open' => ['processing', 'upcoming'],
        'processing' => ['validation', 'input_open'],
        'validation' => ['approval', 'processing', 'input_open'],
        'approval' => ['approved', 'validation'],
        'approved' => ['locked', 'validation'],
        'locked' => ['payment_processing', 'reopened'],
        'payment_processing' => ['paid', 'locked', 'reopened'],
        'paid' => ['closed', 'reopened'],
        'closed' => ['reopened'],
        'reopened' => ['input_open', 'processing', 'validation'],
    ];

    /** Guided run stages (spec 37). */
    public const RUN_STAGES = [1 => 'readiness', 2 => 'collect_inputs', 3 => 'validate', 4 => 'calculate', 5 => 'review_exceptions', 6 => 'compare', 7 => 'approve', 8 => 'lock', 9 => 'generate_outputs', 10 => 'payment', 11 => 'close'];

    public const RUN = ['draft', 'readiness', 'inputs', 'validating', 'calculating', 'review', 'approval', 'approved', 'locked', 'outputs', 'payment', 'closed', 'reopened', 'cancelled'];

    public const RUN_TRANSITIONS = [
        'draft' => ['readiness', 'cancelled'],
        'readiness' => ['inputs', 'cancelled'],
        'inputs' => ['validating', 'cancelled'],
        'validating' => ['calculating', 'inputs'],
        'calculating' => ['review', 'validating'],
        'review' => ['approval', 'calculating', 'inputs'],
        'approval' => ['approved', 'review'],
        'approved' => ['locked', 'review'],
        'locked' => ['outputs', 'reopened'],
        'outputs' => ['payment', 'closed', 'reopened'],
        'payment' => ['closed', 'outputs', 'reopened'],
        'closed' => ['reopened'],
        'reopened' => ['inputs', 'review'],
        'cancelled' => [],
    ];

    /** Statuses in which results may be (re)calculated. Anything past `approved` is locked against ordinary recalculation. */
    public const CALCULABLE_RUN = ['inputs', 'validating', 'calculating', 'review', 'reopened'];

    public const SEVERITIES = ['information', 'warning', 'blocking', 'critical'];

    public const PAYMENT = ['not_prepared', 'prepared', 'submitted', 'processing', 'paid', 'failed', 'partially_paid'];

    public const COMPONENT_KINDS = ['earning', 'deduction', 'tax', 'employee_contribution', 'employer_contribution', 'benefit', 'reimbursement', 'informational'];

    /** Kind groups used by the result store and control totals. */
    public const GROSS_KINDS = ['earning'];

    public const NET_DEDUCTION_KINDS = ['deduction', 'tax', 'employee_contribution'];

    public const EMPLOYER_COST_KINDS = ['employer_contribution'];

    public const INPUT_SOURCES = ['attendance', 'leave', 'overtime', 'variable_pay', 'bonus', 'commission', 'adjustment', 'loan', 'benefit', 'reimbursement', 'import', 'api', 'manual'];

    public const FREQUENCIES = ['weekly', 'biweekly', 'semi_monthly', 'monthly', 'four_weekly', 'custom'];

    public const REVISION_TYPES = ['increment', 'promotion', 'market_correction', 'adjustment', 'transfer', 'demotion', 'other'];

    public static function canTransition(array $map, string $from, string $to): bool
    {
        return in_array($to, $map[$from] ?? [], true);
    }

    public static function assertTransition(array $map, string $from, string $to, string $field = 'state'): void
    {
        if (! self::canTransition($map, $from, $to)) {
            throw ValidationException::withMessages([$field => "A change from \"$from\" to \"$to\" is not allowed. Allowed next steps: ".(implode(', ', $map[$from] ?? []) ?: 'none').'.']);
        }
    }
}
