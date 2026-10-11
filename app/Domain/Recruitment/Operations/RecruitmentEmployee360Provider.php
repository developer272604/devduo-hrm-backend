<?php

namespace App\Domain\Recruitment\Operations;

use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Models\Employee;
use App\Domain\Recruitment\HiringScope;
use App\Domain\Recruitment\Models\{Application, Handover};
use App\Models\User;

/** "Recruitment source" for Employee 360: facts only (source, requisition, dates). No scorecards, offers or compensation. Visible to the hiring team in scope and holders of recruitment.handover. */
final class RecruitmentEmployee360Provider implements Employee360Provider
{
    public function key(): string
    {
        return 'recruitment';
    }

    public function sections(User $viewer, Employee $employee, array $relation): array
    {
        if (!$viewer->allows('recruitment.view') && !$viewer->allows('recruitment.handover')) return [];
        $h = Handover::where('employee_id', $employee->id)->first();
        if (!$h) return [];
        $base = $viewer->allows('recruitment.handover') ? Application::query() : app(HiringScope::class)->applications($viewer);
        $app = $base->whereKey($h->application_id)->with('requisition.job')->first();
        if (!$app) return [];
        return [['key' => 'recruitment_source', 'title' => 'Recruitment source', 'order' => 70, 'source' => 'recruitment', 'items' => [
            ['key' => 'source', 'label' => 'Source', 'value' => $app->source ?: 'Unspecified', 'source' => 'recruitment'],
            ['key' => 'requisition', 'label' => 'Hired for', 'value' => $app->requisition->job->title, 'detail' => 'Requisition '.$app->requisition->uuid, 'source' => 'recruitment'],
            ['key' => 'hired', 'label' => 'Hired through recruitment', 'value' => 'Yes', 'date' => $h->created_at->toDateString(), 'source' => 'recruitment'],
        ]]];
    }
}
