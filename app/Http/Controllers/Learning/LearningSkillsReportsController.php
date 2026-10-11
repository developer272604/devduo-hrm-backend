<?php

namespace App\Http\Controllers\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Learning\{LearningAccess, ReportService, SkillAdminService, SkillService};
use App\Domain\Learning\Models\{Skill, SkillCategory, SkillRequirement};
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Skill taxonomy admin, role requirements, manager validation with evidence, skill gaps, dashboards and masked export. */
final class LearningSkillsReportsController
{
    public function __construct(private SkillAdminService $admin, private SkillService $skills, private ReportService $reports, private LearningAccess $access) {}

    public function categories()
    {
        return $this->admin->categories();
    }

    public function saveCategory(Request $r, ?string $uuid = null)
    {
        $d = $r->validate($uuid ? ['name' => 'sometimes|string|max:120', 'active' => 'sometimes|boolean'] : ['code' => 'required|string|max:40', 'name' => 'required|string|max:120', 'active' => 'sometimes|boolean']);
        $c = $this->admin->saveCategory($d, $uuid ? SkillCategory::where('uuid', $uuid)->firstOrFail() : null);

        return response()->json(['uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'active' => (bool) $c->active], $uuid ? 200 : 201);
    }

    public function saveSkill(Request $r, ?string $uuid = null)
    {
        $req = $uuid ? 'sometimes' : 'required';
        $d = $r->validate(['name' => "$req|string|max:150", 'code' => 'nullable|string|max:40', 'description' => 'nullable|string|max:2000', 'category_uuid' => 'nullable|uuid', 'active' => 'sometimes|boolean']);

        return response()->json($this->admin->presentSkill($this->admin->saveSkill($d, $uuid ? Skill::where('uuid', $uuid)->firstOrFail() : null)), $uuid ? 200 : 201);
    }

    public function saveLevels(Request $r)
    {
        $d = $r->validate(['levels' => 'required|array|min:2|max:10', 'levels.*.rank' => 'required|integer|min:1|max:10|distinct', 'levels.*.name' => 'required|string|max:80', 'levels.*.description' => 'nullable|string|max:300']);

        return $this->admin->saveLevels($d['levels']);
    }

    public function requirements(Request $r)
    {
        Audit::record('skill_requirements.viewed', 'learning', 'skill-requirements');

        return $this->admin->requirements($r->filled('designation_id') ? (int) $r->query('designation_id') : null);
    }

    public function saveRequirement(Request $r)
    {
        $d = $r->validate(['designation_id' => 'required|integer', 'skill_uuid' => 'required|uuid', 'required_rank' => 'required|integer|min:1|max:10', 'mandatory' => 'sometimes|boolean']);
        $row = $this->admin->saveRequirement($r->user(), $d);

        return response()->json(collect($this->admin->requirements((int) $row->designation_id))->firstWhere('uuid', $row->uuid), 200);
    }

    public function deleteRequirement(string $uuid)
    {
        $row = SkillRequirement::where('uuid', $uuid)->firstOrFail();
        $row->delete();
        Audit::record('skill_requirement.deleted', 'learning', 'skill-requirement:'.$uuid);

        return response()->noContent();
    }

    private function employee(Request $r, string $uuid): Employee
    {
        $emp = $this->access->employeeByUuid($uuid);
        abort_unless($this->access->selfOrReaches($r->user(), $emp, 'learning.view'), 404);

        return $emp;
    }

    public function employeeSkills(Request $r, string $uuid)
    {
        $emp = $this->employee($r, $uuid);
        Audit::record('skills.viewed', 'learning', 'employee:'.$emp->uuid);

        return ['profile' => $this->skills->profile($emp, true), 'gap' => $this->skills->gap($emp)];
    }

    public function validateSkill(Request $r, string $uuid, string $skillUuid)
    {
        $d = $r->validate(['level' => 'required|integer|min:1|max:10', 'evidence' => 'required|array', 'evidence.note' => 'required|string|min:5|max:1000', 'evidence.kind' => ['sometimes', Rule::in(SkillAdminService::EVIDENCE_KINDS)], 'evidence.reference' => 'nullable|string|max:255']);
        $emp = $this->access->employeeByUuid($uuid);
        abort_unless($this->access->reaches($r->user(), $emp, 'learning.skills.validate'), 404);

        return $this->admin->validate($r->user(), $emp, Skill::where('uuid', $skillUuid)->firstOrFail(), $d['level'], $d['evidence']);
    }

    public function skillEvidence(Request $r, string $uuid, string $skillUuid)
    {
        $emp = $this->access->employeeByUuid($uuid);
        abort_unless($this->access->reaches($r->user(), $emp, 'learning.skills.validate'), 404);
        Audit::record('skill_evidence.viewed', 'learning', 'employee:'.$emp->uuid);

        return $this->admin->evidence($emp, Skill::where('uuid', $skillUuid)->firstOrFail());
    }

    public function skillGaps(Request $r)
    {
        Audit::record('skill_gaps.viewed', 'learning', 'skill-gaps');

        return $this->skills->teamGaps($r->user(), 'learning.view');
    }

    public function employeeGap(Request $r, string $uuid)
    {
        $emp = $this->employee($r, $uuid);
        Audit::record('skill_gap.viewed', 'learning', 'employee:'.$emp->uuid);

        return $this->skills->gap($emp);
    }

    public function dashboard(Request $r)
    {
        Audit::record('dashboard.viewed', 'learning', 'dashboard');

        return $this->reports->dashboard($r->user());
    }

    public function commandCenter(Request $r)
    {
        $out = $this->reports->commandCenter($r->user());
        Audit::record('command_center.viewed', 'learning', 'command-center');

        return $out;
    }

    public function export(Request $r)
    {
        $rows = $this->reports->exportRows($r->user());

        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            foreach ($rows as $row) fputcsv($h, $row);
            fclose($h);
        }, 'learning-enrolments.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
