<?php

namespace App\Domain\Payroll\Support;

/**
 * Domain event types recorded through App\Domain\Events\DomainEvents::record() (never call other modules directly).
 * Payloads carry uuids and non-monetary facts only: NEVER salary, net pay or bank details (events feed timelines and notifications).
 */
final class PayrollEvents
{
    public const COMPENSATION_ASSIGNED = 'payroll.compensation.assigned';

    public const COMPENSATION_REVISED = 'payroll.compensation.revised';

    public const PAYROLL_OPENED = 'payroll.period.opened';

    public const PAYROLL_CALCULATED = 'payroll.run.calculated';

    public const PAYROLL_VALIDATION_FAILED = 'payroll.run.validation_failed';

    public const PAYROLL_APPROVED = 'payroll.run.approved';

    public const PAYROLL_LOCKED = 'payroll.run.locked';

    public const PAYROLL_REOPENED = 'payroll.run.reopened';

    public const PAYSLIP_GENERATED = 'payroll.payslip.generated';

    public const PAYMENT_FILE_GENERATED = 'payroll.payment_file.generated';

    public const PAYROLL_PAID = 'payroll.run.paid';

    public const SETTLEMENT_CALCULATED = 'payroll.settlement.calculated';

    public const ACCOUNTING_EXPORTED = 'payroll.accounting.exported';

    public const ALL = [
        self::COMPENSATION_ASSIGNED, self::COMPENSATION_REVISED, self::PAYROLL_OPENED, self::PAYROLL_CALCULATED, self::PAYROLL_VALIDATION_FAILED, self::PAYROLL_APPROVED,
        self::PAYROLL_LOCKED, self::PAYROLL_REOPENED, self::PAYSLIP_GENERATED, self::PAYMENT_FILE_GENERATED, self::PAYROLL_PAID, self::SETTLEMENT_CALCULATED, self::ACCOUNTING_EXPORTED,
    ];
}
