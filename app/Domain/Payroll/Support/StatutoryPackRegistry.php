<?php

namespace App\Domain\Payroll\Support;

use App\Domain\Payroll\Contracts\StatutoryPack;

/** Statutory pack handlers by key (payroll_country_packs.handler). Workstream A registers the declarative interpreter under "declarative". */
final class StatutoryPackRegistry extends HandlerRegistry
{
    protected function contract(): string
    {
        return StatutoryPack::class;
    }

    protected function label(): string
    {
        return 'statutory pack handler';
    }

    public function pack(string $key): StatutoryPack
    {
        /** @var StatutoryPack */
        return $this->resolve($key);
    }
}
