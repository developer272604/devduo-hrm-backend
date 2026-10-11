<?php

namespace App\Domain\Learning\Operations;

use App\Domain\Access\Classification;
use App\Domain\Quality\{Finding, QualityRule};
use Illuminate\Support\Facades\DB;

/** Read-only data-quality rules (identifiers only). */
final class LearningQualityRules
{
    /** @return array<int,QualityRule> */
    public static function all(): array
    {
        return [
            self::rule('learning_rule_course_unavailable', 'Mandatory training rules pointing at unpublished courses', function (int $t) {
                foreach (DB::table('learning_rules')->join('learning_courses', 'learning_courses.id', '=', 'learning_rules.course_id')->where('learning_rules.tenant_id', $t)->where('learning_rules.active', true)
                    ->where('learning_courses.status', '!=', 'published')->get(['learning_rules.id', 'learning_rules.uuid', 'learning_rules.name']) as $r)
                    yield new Finding(null, 'lr:'.$r->id, 'An active mandatory training rule uses a course that is not published', 'Publish the course or deactivate rule "'.$r->name.'".', 'warning', ['learning_rule', $r->id], [], Classification::HR_CONFIDENTIAL);
            }),
            self::rule('learning_assignment_inactive_employee', 'Open mandatory training for people who have left', function (int $t) {
                foreach (DB::table('learning_assignments')->join('employees', 'employees.id', '=', 'learning_assignments.employee_id')->where('learning_assignments.tenant_id', $t)->where('learning_assignments.status', 'assigned')
                    ->whereIn('employees.status', ['separated', 'inactive', 'archived'])->get(['learning_assignments.id', 'learning_assignments.employee_id']) as $a)
                    yield new Finding(null, 'la:'.$a->id, 'An open mandatory training assignment belongs to someone who has left', 'Waive the assignment.', 'info', ['employee', (int) $a->employee_id], [], Classification::HR_CONFIDENTIAL);
            }),
            self::rule('learning_requirement_inactive_skill', 'Role skill requirements using inactive skills', function (int $t) {
                foreach (DB::table('learning_skill_requirements')->join('skills', 'skills.id', '=', 'learning_skill_requirements.skill_id')->where('learning_skill_requirements.tenant_id', $t)->where('skills.active', false)
                    ->get(['learning_skill_requirements.id', 'skills.name']) as $r)
                    yield new Finding(null, 'sr:'.$r->id, 'A role requirement uses an inactive skill', 'Remove the requirement for "'.$r->name.'" or reactivate the skill.', 'info', ['learning_skill_requirement', $r->id], [], Classification::MANAGER_SENSITIVE);
            }),
        ];
    }

    private static function rule(string $key, string $label, \Closure $scan): QualityRule
    {
        return new class($key, $label, $scan) implements QualityRule {
            public function __construct(private string $key, private string $label, private \Closure $scanner) {}

            public function key(): string { return $this->key; }

            public function category(): string { return 'learning'; }

            public function label(): string { return $this->label; }

            public function scan(int $tenantId): iterable { return ($this->scanner)($tenantId); }
        };
    }
}
