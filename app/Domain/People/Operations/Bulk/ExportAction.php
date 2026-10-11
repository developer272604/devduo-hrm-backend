<?php

namespace App\Domain\People\Operations\Bulk;

use App\Domain\People\Models\Employee;
use App\Domain\People\Operations\BulkAction;
use App\Domain\People\Operations\BulkBatchAction;
use App\Domain\People\Operations\DescribesBulkAction;
use App\Domain\People\Operations\ExportService;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/** Creates an export job for the selected employees. */
class ExportAction implements BulkAction, BulkBatchAction, DescribesBulkAction
{
    public function key(): string
    {
        return 'export';
    }

    public function label(): string
    {
        return 'Export';
    }

    public function description(): string
    {
        return 'Export the selected employees to CSV or Excel.';
    }

    public function permission(): string
    {
        return 'people.export';
    }

    public function validate(array $params): array
    {
        return Validator::make($params, ['format' => 'required|in:csv,xlsx', 'columns' => 'nullable|array', 'columns.*' => 'string'])->validate();
    }

    public function preview(User $user, Collection $employees, array $params): array
    {
        $problem = app(ExportService::class)->columnProblem($user, $params['columns'] ?? null);
        $out = [];
        foreach ($employees as $e) {
            $out[$e->id] = $problem ? ['status' => 'error', 'reason' => $problem] : ['status' => 'ok'];
        }

        return $out;
    }

    public function apply(User $user, Employee $employee, array $params): void
    {
        throw new \LogicException('Export runs as a batch.');
    }

    public function applyBatch(User $user, Collection $employees, array $params): array
    {
        $export = app(ExportService::class)->request($user, ['format' => $params['format'], 'columns' => $params['columns'] ?? null, 'selected_ids' => $employees->pluck('id')->all()]);

        return ['export_uuid' => $export->uuid];
    }
}
