<?php

namespace App\Http\Controllers\People\Config;

use App\Domain\People\Config\CustomFieldService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

class EmployeeCustomFieldController extends PeopleController
{
    public function show(Request $r, string $uuid, CustomFieldService $fields)
    {
        return ['sections' => $fields->sectionsFor($r->user(), $this->employee($r, $uuid))];
    }

    public function update(Request $r, string $uuid, CustomFieldService $fields)
    {
        $data = $r->validate(['values' => 'required|array|min:1', 'effective_from' => 'nullable|date_format:Y-m-d']);
        $employee = $this->employee($r, $uuid);
        $fields->setValues($r->user(), $employee, $data['values'], $data['effective_from'] ?? null);

        return ['sections' => $fields->sectionsFor($r->user(), $employee)];
    }

    public function history(Request $r, string $uuid, string $key, CustomFieldService $fields)
    {
        return ['data' => $fields->historyFor($r->user(), $this->employee($r, $uuid), $key)];
    }
}
