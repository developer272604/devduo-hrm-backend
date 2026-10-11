<?php

namespace App\Domain\Recruitment\Operations;

use App\Domain\Access\Classification;
use App\Domain\Quality\{Finding, QualityRule};
use Illuminate\Support\Facades\DB;

/** Read-only data-quality rules for recruitment. No applicant personal data in findings (uuid references only). */
final class RecruitmentQualityRules
{
    /** @return array<int,QualityRule> */
    public static function all(): array
    {
        return [
            self::rule('recruitment_retention_expired', 'Applicant data kept past its retention date', function (int $t) {
                foreach (DB::table('recruitment_candidates')->where('tenant_id', $t)->whereNull('anonymized_at')->where('retention_until', '<', now('UTC')->toDateString())->get(['id', 'uuid']) as $c)
                    yield new Finding(null, 'cand:'.$c->id, 'Applicant data is past its retention date', 'Anonymize or review the applicant record (candidate '.$c->uuid.').', 'warning', ['recruitment_candidate', $c->id], [], Classification::HR_CONFIDENTIAL);
            }),
            self::rule('recruitment_stale_requisition', 'Open requisitions past target date without active applications', function (int $t) {
                foreach (DB::table('recruitment_requisitions')->where('tenant_id', $t)->where('status', 'open')->where('target_date', '<', now('UTC')->toDateString())
                    ->whereNotExists(fn ($q) => $q->from('recruitment_applications')->whereColumn('requisition_id', 'recruitment_requisitions.id')->where('status', 'active'))->get(['id', 'uuid']) as $r)
                    yield new Finding(null, 'req:'.$r->id, 'Open requisition has no active applicants and is past its target date', 'Requisition '.$r->uuid.'.', 'warning', ['recruitment_requisition', $r->id], [], Classification::MANAGER_SENSITIVE);
            }),
            self::rule('recruitment_hired_without_employee', 'Hired applications without an employee link', function (int $t) {
                foreach (DB::table('recruitment_applications')->where('tenant_id', $t)->where('status', 'hired')->whereNull('employee_id')->get(['id', 'uuid']) as $a)
                    yield new Finding(null, 'app:'.$a->id, 'Hired application has no employee record', 'Application '.$a->uuid.'.', 'action_required', ['recruitment_application', $a->id], [], Classification::HR_CONFIDENTIAL);
            }),
            self::rule('recruitment_stalled_application', 'Applications waiting in a stage for over 30 days', function (int $t) {
                foreach (DB::table('recruitment_applications')->where('tenant_id', $t)->where('status', 'active')->where('stage_entered_at', '<', now('UTC')->subDays(30)->format('Y-m-d H:i:s'))->get(['id', 'uuid']) as $a)
                    yield new Finding(null, 'stall:'.$a->id, 'Application has not moved for over 30 days', 'Application '.$a->uuid.'.', 'info', ['recruitment_application', $a->id], [], Classification::MANAGER_SENSITIVE);
            }),
        ];
    }

    private static function rule(string $key, string $label, \Closure $scan): QualityRule
    {
        return new class($key, $label, $scan) implements QualityRule {
            public function __construct(private string $key, private string $label, private \Closure $scanner) {}

            public function key(): string { return $this->key; }

            public function category(): string { return 'recruitment'; }

            public function label(): string { return $this->label; }

            public function scan(int $tenantId): iterable { return ($this->scanner)($tenantId); }
        };
    }
}
