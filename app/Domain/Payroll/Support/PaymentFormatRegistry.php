<?php

namespace App\Domain\Payroll\Support;

use App\Domain\Payroll\Contracts\PaymentFormat;

/** Payment-file adapters by code. Foundation registers "generic_csv"; bank / country adapters register from workstream C. */
final class PaymentFormatRegistry extends HandlerRegistry
{
    protected function contract(): string
    {
        return PaymentFormat::class;
    }

    protected function label(): string
    {
        return 'payment format';
    }

    public function format(string $code): PaymentFormat
    {
        /** @var PaymentFormat */
        return $this->resolve($code);
    }
}
