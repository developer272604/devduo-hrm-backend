<?php

namespace App\Domain\Learning\Operations;

use App\Domain\Learning\LearningAccess;
use App\Domain\Learning\Models\{Assignment, EmployeeCert, Enrolment};
use App\Domain\Learning\SkillService;
use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;

/** Employee 360 "Learning" section: facts only (counts and dates). Free-text notes, evidence and assessment answers are never shown here. */
final class LearningEmployee360Provider implements Employee360Provider
{
    public function key(): string
    {
        return 'learning';
    }

    public function sections(User $viewer, Employee $employee, array $relation): array
    {
        $access = app(LearningAccess::class);
        $self = $access->isSelf($viewer, $employee) && $viewer->allows('learning.self');
        if (! $self && ! $access->reaches($viewer, $employee, 'learning.view')) return [];
        $today = TenantClock::today(); $items = [];
        $enr = Enrolment::where('employee_id', $employee->id)->get(['status', 'completed_at']);
        if ($enr->count()) {
            $items[] = ['key' => 'courses', 'label' => 'Courses', 'value' => $enr->where('status', 'completed')->count().' completed, '.$enr->whereIn('status', ['enrolled', 'in_progress'])->count().' in progress', 'source' => 'learning'];
        }
        $open = Assignment::where('employee_id', $employee->id)->where('status', 'assigned')->get();
        if ($open->count()) {
            $overdue = $open->filter(fn ($a) => $a->due_on->format('Y-m-d') < $today)->count();
            $items[] = ['key' => 'mandatory', 'label' => 'Mandatory training', 'value' => $open->count().' open, '.$overdue.' overdue', 'detail' => 'Next due '.$open->min(fn ($a) => $a->due_on->format('Y-m-d')), 'href' => '/learning/compliance', 'source' => 'learning'];
        }
        $certs = EmployeeCert::where('employee_id', $employee->id)->whereIn('status', ['active', 'expired'])->get();
        if ($certs->count()) $items[] = ['key' => 'certificates', 'label' => 'Certificates', 'value' => $certs->where('status', 'active')->count().' active, '.$certs->where('status', 'expired')->count().' expired', 'source' => 'learning'];
        $gap = app(SkillService::class)->gap($employee);
        if ($gap['requirements']) $items[] = ['key' => 'skill_gaps', 'label' => 'Role skill gaps', 'value' => $gap['gap_count'].' of '.$gap['requirements'].' required skills below target', 'source' => 'learning'];

        return $items ? [['key' => 'learning', 'title' => 'Learning', 'order' => 88, 'source' => 'learning', 'items' => $items]] : [];
    }
}
