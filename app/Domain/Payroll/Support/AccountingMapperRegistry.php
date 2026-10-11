<?php

namespace App\Domain\Payroll\Support;

use App\Domain\Payroll\Contracts\AccountingMapper;

/** Accounting mapper / journal export adapters by code (workstream C registers them). */
final class AccountingMapperRegistry extends HandlerRegistry
{
    protected function contract(): string
    {
        return AccountingMapper::class;
    }

    protected function label(): string
    {
        return 'accounting mapper';
    }

    public function mapper(string $code): AccountingMapper
    {
        /** @var AccountingMapper */
        return $this->resolve($code);
    }
}
