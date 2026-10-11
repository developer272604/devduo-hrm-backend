<?php

namespace App\Domain\People\Operations\Bulk;

use App\Domain\People\Models\Employee;
use App\Domain\People\Operations\BulkAction;
use App\Domain\People\Operations\DescribesBulkAction;
use App\Domain\People\Services\StatusService;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

abstract class StatusActionBase implements BulkAction, DescribesBulkAction
{
    abstract protected function target(Employee $e): string;

    public function permission(): string
    {
        return 'people.manage';
    }

    public function validate(array $params): array
    {
        return Validator::make($params, ['reason' => 'nullable|string|max:1000', 'effective_date' => 'nullable|date_format:Y-m-d'])->validate();
    }

    public function preview(User $user, Collection $employees, array $params): array
    {
        $status = app(StatusService::class);
        $out = [];
        foreach ($employees as $e) {
            $to = $this->target($e);
            $label = strtolower(StatusService::LABELS[$to]);
            $out[$e->id] = match (true) {
                $e->status === $to || ($to === 'active' && $e->status === 'probation') => ['status' => 'skipped', 'reason' => 'Already '.strtolower(StatusService::LABELS[$e->status]).'.'],
                in_array($e->status, ['separated', 'archived'], true) => ['status' => 'error', 'reason' => 'Separated employees cannot be moved to '.$label.' here. Use rehire.'],
                ! in_array($to, $status->allowed($e->status), true) => ['status' => 'error', 'reason' => 'An employee cannot move from '.strtolower(StatusService::LABELS[$e->status]).' to '.$label.'.'],
                default => ['status' => 'ok'],
            };
        }

        return $out;
    }

    public function apply(User $user, Employee $employee, array $params): void
    {
        app(StatusService::class)->transition($employee, $this->target($employee), ['reason' => $params['reason'] ?? 'Bulk update', 'reason_code' => 'bulk', 'effective_date' => $params['effective_date'] ?? null]);
    }
}
