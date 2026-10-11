<?php

namespace App\Domain\People\Operations;

interface DescribesBulkAction
{
    public function label(): string;

    public function description(): string;
}
