<?php

namespace App\Domain\People\Operations\Bulk;

use App\Domain\People\Models\Employee;

class ActivateAction extends StatusActionBase
{
    public function key(): string
    {
        return 'activate';
    }

    public function label(): string
    {
        return 'Activate';
    }

    public function description(): string
    {
        return 'Move the selected employees to active (or probation when it applies).';
    }

    protected function target(Employee $e): string
    {
        return $e->status === 'preboarding' && $e->probation_applicable ? 'probation' : 'active';
    }
}
