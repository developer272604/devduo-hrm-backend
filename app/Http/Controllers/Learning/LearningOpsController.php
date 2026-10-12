<?php

namespace App\Http\Controllers\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Learning\{AssessmentService, CatalogService, CertificateService, ComplianceService, EnrolmentService, LearningAccess};
use App\Domain\Learning\Models\{Assessment, Assignment, Certification, Course, EmployeeCert, Enrolment, Path, Rule, Session};
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule as V;

/** Phase 9 operations: assignments, enrolment decisions, attendance/completion, compliance, assessments, certificates. Every endpoint checks permission + DataScope and audits. */
final class LearningOpsController
{
    public function __construct(private EnrolmentService $enrol, private ComplianceService $compliance, private AssessmentService $assess, private CertificateService $certs, private CatalogService $catalog, private LearningAccess $access) {}

    private function own(Request $r): int
    {
        return $this->access->own($r->user())[0] ?? abort(403, 'No employee profile is linked to this account.');
    }

    /** Pickers: people in scope, groups, org records. */
    public function lookups(Request $r)
    {
        $u = $r->user(); $perm = $this->access->heldPermission($u, ['learning.enrol.manage', 'learning.assign.manage', 'learning.skills.manage']);
        $q = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', $this->access->reachIds($u, $perm))->whereNotIn('employees.status', ['separated', 'inactive', 'archived'])
            ->when($r->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('people.legal_first_name', 'like', '%'.$r->query('search').'%')->orWhere('people.legal_last_name', 'like', '%'.$r->query('search').'%')->orWhere('employees.employee_number', 'like', '%'.$r->query('search').'%')));
        Audit::record('lookups.viewed', 'learning', 'learning-lookups');

        return ['employees' => $q->orderBy('people.legal_first_name')->limit(100)->get(['employees.uuid', 'employees.employee_number', 'people.legal_first_name', 'people.legal_last_name'])
            ->map(fn ($e) => ['uuid' => $e->uuid, 'number' => $e->employee_number, 'name' => trim($e->legal_first_name.' '.$e->legal_last_name)]),
            'groups' => DB::table('employee_groups')->where('tenant_id', $u->tenant_id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'designations' => DB::table('designations')->where('tenant_id', $u->tenant_id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'departments' => DB::table('departments')->where('tenant_id', $u->tenant_id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'legal_entities' => DB::table('legal_entities')->where('tenant_id', $u->tenant_id)->where('active', true)->orderBy('name')->get(['id', 'name'])];
    }

    // ---- Enrolments ----------------------------------------------------------------------------------------------------------------

    public function enrolments(Request $r)
    {
        $u = $r->user(); $perm = $this->access->heldPermission($u, ['learning.enrol.manage', 'learning.enrol.approve', 'learning.view']);
        $q = Enrolment::whereIn('employee_id', $this->access->reachIds($u, $perm));
        if ($r->filled('status')) $q->where('status', $r->query('status'));
        if ($r->filled('course_uuid')) $q->whereIn('course_id', Course::where('uuid', $r->query('course_uuid'))->select('id'));
        if ($r->filled('employee_uuid')) $q->whereIn('employee_id', Employee::where('uuid', $r->query('employee_uuid'))->select('id'));
        $page = $q->latest('id')->paginate(min(100, max(1, (int) $r->query('per_page', 25))));
        $people = $this->access->people($page->pluck('employee_id')); $courses = Course::whereIn('id', $page->pluck('course_id'))->get()->keyBy('id');
        Audit::record('enrolments.viewed', 'learning', 'team-enrolments', ['count' => $page->total()]);

        return $page->through(fn ($e) => $this->enrol->present($e, $people, $courses[$e->course_id]));
    }

    public function assignCourse(Request $r)
    {
        $d = $r->validate(['course_uuid' => 'required|uuid', 'employee_uuids' => 'sometimes|array|max:200', 'employee_uuids.*' => 'uuid', 'group_id' => 'nullable|integer', 'session_uuid' => 'nullable|uuid', 'due_on' => 'nullable|date_format:Y-m-d']);
        $course = Course::where('uuid', $d['course_uuid'])->firstOrFail();
        $session = ! empty($d['session_uuid']) ? Session::where('uuid', $d['session_uuid'])->where('course_id', $course->id)->where('status', 'scheduled')->firstOrFail() : null;
        $ids = $this->enrol->targets($r->user(), $d['employee_uuids'] ?? [], $d['group_id'] ?? null);

        return response()->json($this->enrol->assignCourse($r->user(), $course, $ids, $d['due_on'] ?? null, $session), 201);
    }

    public function assignPath(Request $r)
    {
        $d = $r->validate(['path_uuid' => 'required|uuid', 'employee_uuids' => 'sometimes|array|max:200', 'employee_uuids.*' => 'uuid', 'group_id' => 'nullable|integer', 'due_on' => 'nullable|date_format:Y-m-d']);
        $path = Path::where('uuid', $d['path_uuid'])->where('status', 'published')->firstOrFail();

        return response()->json($this->enrol->assignPath($r->user(), $path, $this->enrol->targets($r->user(), $d['employee_uuids'] ?? [], $d['group_id'] ?? null), $d['due_on'] ?? null), 201);
    }

    public function decide(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => ['required', V::in(['approve', 'reject'])], 'note' => 'nullable|string|max:500']);

        return $this->enrol->present($this->enrol->decide($r->user(), Enrolment::where('uuid', $uuid)->firstOrFail(), $d['decision'], $d['note'] ?? null));
    }

    public function attendance(Request $r, string $uuid)
    {
        $d = $r->validate(['session_date' => 'required|date_format:Y-m-d', 'present' => 'required|boolean']);

        return $this->enrol->present($this->enrol->markAttendance($r->user(), Enrolment::where('uuid', $uuid)->firstOrFail(), $d['session_date'], (bool) $d['present']));
    }

    public function complete(Request $r, string $uuid)
    {
        $d = $r->validate(['score' => 'nullable|numeric|min:0|max:100', 'passed' => 'sometimes|boolean']);

        return $this->enrol->present($this->enrol->complete($r->user(), Enrolment::where('uuid', $uuid)->firstOrFail(), isset($d['score']) ? (string) $d['score'] : null, $d['passed'] ?? true));
    }

    public function cancelEnrolment(Request $r, string $uuid)
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);
        $result = $this->enrol->cancel($r->user(), Enrolment::where('uuid', $uuid)->firstOrFail(), $d['note'] ?? null);

        return ['cancelled' => $this->enrol->present($result['cancelled']), 'promoted' => $result['promoted'] ? $this->enrol->present($result['promoted']) : null];
    }

    public function cancelSession(Request $r, string $uuid)
    {
        $session = $this->catalog->cancelSession($r->user(), Session::where('uuid', $uuid)->firstOrFail());

        return ['uuid' => $session->uuid, 'status' => $session->status];
    }

    // ---- Compliance ----------------------------------------------------------------------------------------------------------------

    public function rules()
    {
        Audit::record('compliance_rules.viewed', 'learning', 'compliance-rules');

        return Rule::orderBy('name')->get()->map(fn ($x) => $this->compliance->presentRule($x));
    }

    private function ruleRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return ['name' => "$req|string|max:160", 'course_uuid' => "$req|uuid", 'designation_id' => 'nullable|integer', 'department_id' => 'nullable|integer', 'legal_entity_id' => 'nullable|integer', 'country' => 'nullable|string|size:2',
            'due_days' => "$req|integer|min:1|max:730", 'recurrence_months' => 'nullable|integer|min:1|max:120', 'escalation_days' => 'sometimes|integer|min:0|max:365', 'active' => 'sometimes|boolean'];
    }

    public function createRule(Request $r)
    {
        return response()->json($this->compliance->presentRule($this->compliance->saveRule($r->user(), $r->validate($this->ruleRules(true)))), 201);
    }

    public function updateRule(Request $r, string $uuid)
    {
        return $this->compliance->presentRule($this->compliance->saveRule($r->user(), $r->validate($this->ruleRules(false)), Rule::where('uuid', $uuid)->firstOrFail()));
    }

    public function runCompliance()
    {
        $gen = $this->compliance->generate(); $esc = $this->compliance->escalate();
        Audit::record('compliance.run_manual', 'learning', 'compliance');

        return ['generated' => $gen, 'escalation' => $esc];
    }

    public function complianceStatus(Request $r)
    {
        $u = $r->user(); $perm = $this->access->heldPermission($u, ['learning.assign.manage', 'learning.view']);
        Audit::record('compliance.viewed', 'learning', 'compliance-status');

        return $this->compliance->status($u, $perm, $r->query('status'));
    }

    public function assignments(Request $r)
    {
        $u = $r->user(); $perm = $this->access->heldPermission($u, ['learning.assign.manage', 'learning.view']);
        $q = Assignment::whereIn('employee_id', $this->access->reachIds($u, $perm));
        if ($r->filled('employee_uuid')) $q->whereIn('employee_id', Employee::where('uuid', $r->query('employee_uuid'))->select('id'));
        if ($r->filled('status')) $q->where('status', $r->query('status'));
        $rows = $q->orderBy('due_on')->limit(200)->get(); $people = $this->access->people($rows->pluck('employee_id'));
        Audit::record('assignments.viewed', 'learning', 'assignments');

        return $rows->map(fn ($a) => $this->compliance->presentAssignment($a, $people));
    }

    public function myAssignments(Request $r)
    {
        return Assignment::where('employee_id', $this->own($r))->orderBy('due_on')->get()->map(fn ($a) => $this->compliance->presentAssignment($a));
    }

    public function waive(Request $r, string $uuid)
    {
        $d = $r->validate(['reason' => 'required|string|max:300']);
        $a = Assignment::where('uuid', $uuid)->firstOrFail();
        abort_unless($this->access->reaches($r->user(), $a->employee_id, 'learning.assign.manage'), 404);
        abort_if($this->access->isSelf($r->user(), $a->employee_id), 403, 'You cannot waive your own training.');

        return $this->compliance->presentAssignment($this->compliance->waive($r->user(), $a, $d['reason']));
    }

    // ---- Assessments ---------------------------------------------------------------------------------------------------------------

    public function createAssessment(Request $r, string $uuid)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'pass_percent' => 'nullable|numeric|min:0|max:100', 'max_attempts' => 'nullable|integer|min:1|max:50',
            'questions' => 'required|array|min:1|max:100', 'questions.*.text' => 'required|string|max:1000', 'questions.*.options' => 'required|array|min:2|max:10', 'questions.*.options.*' => 'required|string|max:300',
            'questions.*.correct' => 'required|array|min:1', 'questions.*.correct.*' => 'integer|min:0', 'questions.*.points' => 'sometimes|numeric|min:0.5|max:100']);
        $a = $this->catalog->createAssessment($r->user(), Course::where('uuid', $uuid)->firstOrFail(), $d);

        return response()->json($this->assess->presentFull($a->refresh()), 201);
    }

    public function assessments(string $uuid)
    {
        Audit::record('assessment.key_viewed', 'learning', 'course:'.$uuid);

        return Assessment::where('course_id', Course::where('uuid', $uuid)->firstOrFail()->id)->latest('id')->get()->map(fn ($a) => $this->assess->presentFull($a));
    }

    public function takeAssessment(Request $r, string $uuid)
    {
        $c = Course::where('uuid', $uuid)->where('status', 'published')->firstOrFail(); $emp = $this->own($r);
        abort_unless(Enrolment::where('employee_id', $emp)->where('course_id', $c->id)->whereIn('status', ['enrolled', 'in_progress', 'completed', 'failed'])->exists(), 403, 'Enrol in the course to see its assessment.');
        $a = $this->assess->activeFor($c) ?? abort(404, 'This course has no assessment.');
        Audit::record('assessment.opened', 'learning', 'assessment:'.$a->uuid);

        return $this->assess->presentForTaker($a, $emp);
    }

    public function submitAssessment(Request $r, string $uuid)
    {
        $d = $r->validate(['answers' => 'present|array', 'answers.*' => 'array']);

        return response()->json($this->assess->submit($r->user(), Assessment::where('uuid', $uuid)->where('active', true)->firstOrFail(), $this->own($r), $d['answers']), 201);
    }

    // ---- Certificates --------------------------------------------------------------------------------------------------------------

    public function certifications()
    {
        return Certification::orderBy('name')->get()->map(fn ($c) => $this->certs->presentDefinition($c));
    }

    private function certRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return ['code' => "$req|string|max:40", 'name' => "$req|string|max:160", 'issuer' => 'nullable|string|max:160', 'validity_months' => 'nullable|integer|min:1|max:600', 'renewal_course_uuid' => 'nullable|uuid',
            'reminder_days' => 'sometimes|array|min:1|max:6', 'reminder_days.*' => 'integer|min:1|max:730', 'active' => 'sometimes|boolean'];
    }

    public function createCertification(Request $r)
    {
        return response()->json($this->certs->presentDefinition($this->certs->saveDefinition($r->user(), $r->validate($this->certRules(true)))), 201);
    }

    public function updateCertification(Request $r, string $uuid)
    {
        return $this->certs->presentDefinition($this->certs->saveDefinition($r->user(), $r->validate($this->certRules(false)), Certification::where('uuid', $uuid)->firstOrFail()));
    }

    public function myCertificates(Request $r)
    {
        return EmployeeCert::where('employee_id', $this->own($r))->latest('id')->get()->map(fn ($c) => $this->certs->present($c));
    }

    public function certificates(Request $r)
    {
        $u = $r->user(); $q = EmployeeCert::whereIn('employee_id', $this->access->reachIds($u, 'learning.view'));
        if ($r->filled('status')) $q->where('status', $r->query('status'));
        $rows = $q->orderBy('expires_on')->limit(200)->get(); $people = $this->access->people($rows->pluck('employee_id'));
        Audit::record('certificates.viewed', 'learning', 'certificates');

        return $rows->map(fn ($c) => $this->certs->present($c, $people));
    }

    public function printCertificate(Request $r, string $uuid)
    {
        $c = EmployeeCert::where('uuid', $uuid)->firstOrFail();
        abort_unless($this->access->selfOrReaches($r->user(), $c->employee_id, 'learning.view'), 404);
        Audit::record('certificate.printed', 'learning', 'certificate:'.$c->uuid);

        return response($this->certs->printHtml($c), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
