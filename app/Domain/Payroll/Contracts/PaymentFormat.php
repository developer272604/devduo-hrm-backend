<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Payroll\Contracts\Dto\GeneratedFile;
use App\Domain\Payroll\Contracts\Dto\PaymentBatchData;

/**
 * Bank / provider payment-file adapter. NEVER hard-code one bank format in the engine: each format is an adapter registered under a
 * stable code (and optionally limited to countries). Adapters are pure: they turn an approved batch snapshot into file bytes.
 * Generating a file is NOT payment: status "paid" needs confirmation (integration or manual) - see payment_batches.confirmation_reference.
 */
interface PaymentFormat
{
    public function code(): string;

    public function label(): string;

    /** ISO country codes this format is meant for; empty = any. @return array<int,string> */
    public function countries(): array;

    /** User-facing problems that prevent generation (missing beneficiary fields, currency not supported, ...). @return array<int,string> */
    public function validate(PaymentBatchData $batch): array;

    public function generate(PaymentBatchData $batch): GeneratedFile;
}
