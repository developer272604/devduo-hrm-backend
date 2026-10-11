<?php

namespace App\Http\Controllers\People\Insights;

use App\Domain\People\Employee360\Employee360;
use App\Domain\People\Insights\ProfileCompleteness;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

/** Employee 360 and profile completeness of one employee. */
class Employee360Controller extends PeopleController
{
    public function show(Request $r, string $uuid, Employee360 $registry)
    {
        return $registry->build($r->user(), $this->employee($r, $uuid));
    }

    public function completeness(Request $r, string $uuid, ProfileCompleteness $completeness)
    {
        $employee = $this->employee($r, $uuid);

        return ['employee_uuid' => $employee->uuid] + $completeness->for($employee, $r->user());
    }

    public function completenessSummary(Request $r, ProfileCompleteness $completeness)
    {
        return $completeness->summary($r->user());
    }
}
