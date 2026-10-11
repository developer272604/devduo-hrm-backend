<?php

namespace App\Domain\Lifecycle\Operations;

use App\Domain\Access\Classification;
use App\Domain\Quality\{Finding, QualityRule};
use Illuminate\Support\Facades\DB;

/** Read-only data-quality rules for lifecycle coverage. No personal data in findings (uuid references only). */
final class LifecycleQualityRules
{
    /** @return array<int,QualityRule> */
    public static function all(): array
    {
        return [
            self::rule('lifecycle_preboarder_without_checklist', 'Pre-joiners without an onboarding checklist', function (int $t) {
                foreach (DB::table('employees')->where('tenant_id', $t)->where('status', 'preboarding')->whereNull('deleted_at')
                    ->whereNotExists(fn ($q) => $q->from('lifecycle_cases')->whereColumn('employee_id', 'employees.id')->where('kind', 'onboarding')->whereIn('status', ['open', 'completed']))->get(['id']) as $e)
                    yield new Finding($e->id, 'preb:'.$e->id, 'Pre-joiner has no onboarding checklist', 'Start onboarding so tasks are assigned before day one.', 'warning', ['employee', $e->id], [], Classification::MANAGER_SENSITIVE);
            }),
            self::rule('lifecycle_leaver_without_checklist', 'Leavers in notice without an offboarding checklist', function (int $t) {
                foreach (DB::table('employees')->where('tenant_id', $t)->where('status', 'notice_period')->whereNull('deleted_at')
                    ->whereNotExists(fn ($q) => $q->from('lifecycle_cases')->whereColumn('employee_id', 'employees.id')->where('kind', 'offboarding')->whereIn('status', ['open', 'completed']))->get(['id']) as $e)
                    yield new Finding($e->id, 'leav:'.$e->id, 'Employee on notice has no offboarding checklist', 'Start offboarding so clearance can run before the last day.', 'warning', ['employee', $e->id], [], Classification::HR_CONFIDENTIAL);
            }),
            self::rule('lifecycle_task_without_owner', 'Open lifecycle tasks without an owner', function (int $t) {
                foreach (DB::table('lifecycle_tasks')->where('tenant_id', $t)->where('status', 'pending')->whereNull('owner_user_id')->get(['id', 'uuid']) as $x)
                    yield new Finding(null, 'noown:'.$x->id, 'A lifecycle task has no owner', 'Assign task '.$x->uuid.' so it reaches My Work.', 'action_required', ['lifecycle_task', $x->id], [], Classification::MANAGER_SENSITIVE);
            }),
            self::rule('lifecycle_separated_without_alumni', 'Separated employees missing an alumni record', function (int $t) {
                foreach (DB::table('employees')->where('tenant_id', $t)->where('status', 'separated')->whereNull('deleted_at')
                    ->whereNotExists(fn ($q) => $q->from('lifecycle_alumni')->whereColumn('employee_id', 'employees.id'))->get(['id']) as $e)
                    yield new Finding($e->id, 'alum:'.$e->id, 'Separated employee has no alumni record', 'Rehire eligibility is not recorded for this leaver.', 'info', ['employee', $e->id], [], Classification::HR_CONFIDENTIAL);
            }),
        ];
    }

    private static function rule(string $key, string $label, \Closure $scan): QualityRule
    {
        return new class($key, $label, $scan) implements QualityRule {
            public function __construct(private string $key, private string $label, private \Closure $scanner) {}

            public function key(): string { return $this->key; }

            public function category(): string { return 'lifecycle'; }

            public function label(): string { return $this->label; }

            public function scan(int $tenantId): iterable { return ($this->scanner)($tenantId); }
        };
    }
}
