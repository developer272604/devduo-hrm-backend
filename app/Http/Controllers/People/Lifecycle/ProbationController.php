<?php

namespace App\Http\Controllers\People\Lifecycle;

use App\Domain\People\Lifecycle\ProbationService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

class ProbationController extends PeopleController
{
    public function __construct(private ProbationService $service) {}

    public function confirm(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $data = $r->validate(['confirmation_date' => 'nullable|date_format:Y-m-d', 'comments' => 'nullable|string|max:1000']);

        return $this->summary($this->service->confirm($r->user(), $employee, $data['confirmation_date'] ?? null, $data['comments'] ?? null));
    }

    public function extend(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $data = $r->validate(['new_end_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:1000'], ['reason.required' => 'Give a reason for extending probation.']);

        return $this->summary($this->service->extend($r->user(), $employee, $data['new_end_date'], $data['reason']));
    }

    private function summary($e): array
    {
        return ['uuid' => $e->uuid, 'status' => $e->status, 'probation_end' => $e->probation_end?->toDateString(), 'confirmation_date' => $e->confirmation_date?->toDateString(), 'probation_extensions' => $e->probation_extensions];
    }
}
