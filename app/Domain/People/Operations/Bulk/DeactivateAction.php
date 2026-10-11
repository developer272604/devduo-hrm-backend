<?php

namespace App\Domain\People\Operations\Bulk;

use App\Domain\People\Models\Employee;

class DeactivateAction extends StatusActionBase
{
    public function key(): string
    {
        return 'deactivate';
    }

    public function label(): string
    {
        return 'Deactivate';
    }

    public function description(): string
    {
        return 'Move the selected employees to inactive.';
    }

    protected function target(Employee $e): string
    {
        return 'inactive';
    }
}
