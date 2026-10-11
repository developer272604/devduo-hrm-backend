<?php

namespace App\Domain\Access;

use App\Domain\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class WorkspaceProvisioner
{
    /** key => [group, label]. Groups drive the permission matrix in the role editor. */
    public const PERMISSIONS = [
        // Payroll (Phase 5). Compensation and payroll results are highly sensitive: none of the admin keys below is granted to
        // Manager / Team member / HR administrator by default (only the *_own self-service keys are).
        'payroll.configure' => ['Payroll', 'Configure payroll groups, calendars, periods, salary components and structures'],
        'payroll.country_pack.manage' => ['Payroll', 'Draft country pack versions and statutory configuration'],
        'payroll.country_pack.publish' => ['Payroll', 'Publish, verify and enable country pack versions (strict)'],
        'payroll.compensation.view' => ['Payroll', 'View scoped confidential compensation'],
        'payroll.compensation.manage' => ['Payroll', 'Propose compensation revisions'],
        'payroll.compensation.approve' => ['Payroll', 'Independently approve compensation revisions'],
        'payroll.compensation.mass' => ['Payroll', 'Prepare mass salary revisions and compensation imports'],
        'payroll.compensation.reveal' => ['Payroll', 'Reveal unmasked compensation amounts with a recorded purpose (audited)'],
        'payroll.view' => ['Payroll', 'View payroll operations: command center, readiness, runs'],
        'payroll.inputs.manage' => ['Payroll', 'Create and edit payroll inputs'],
        'payroll.inputs.approve' => ['Payroll', 'Approve variable payroll inputs'],
        'payroll.inputs.import' => ['Payroll', 'Import payroll inputs'],
        'payroll.run' => ['Payroll', 'Create, validate and calculate payroll runs'],
        'payroll.approve' => ['Payroll', 'Independently approve payroll runs (maker-checker)'],
        'payroll.lock' => ['Payroll', 'Lock approved payroll'],
        'payroll.unlock' => ['Payroll', 'Request payroll reopen with a reason (strict)'],
        'payroll.unlock.approve' => ['Payroll', 'Approve payroll reopen requests (maker-checker)'],
        'payroll.adjust' => ['Payroll', 'Propose payroll adjustments, arrears and manual net-pay changes'],
        'payroll.adjust.approve' => ['Payroll', 'Approve payroll adjustments (maker-checker)'],
        'payroll.results.view' => ['Payroll', 'View payroll results of employees within your data scope'],
        'payroll.explain' => ['Payroll', 'Use the payroll explainer, difference view and change story'],
        'payroll.simulate' => ['Payroll', 'Run non-mutating payroll formula simulations'],
        'payroll.payslips.manage' => ['Payroll', 'Generate, publish and correct payslips'],
        'payroll.payments.prepare' => ['Payroll', 'Prepare payment batches and generate bank files'],
        'payroll.payments.approve' => ['Payroll', 'Approve bank file generation (maker-checker)'],
        'payroll.payments.confirm' => ['Payroll', 'Record payment confirmation and failures'],
        'payroll.accounting.manage' => ['Payroll', 'Manage accounting mappings and cost allocation'],
        'payroll.accounting.export' => ['Payroll', 'Generate and export payroll journals'],
        'payroll.statutory.reports' => ['Payroll', 'Generate statutory reports and filings'],
        'payroll.reports.view' => ['Payroll', 'View payroll reports'],
        'payroll.export' => ['Payroll', 'Export payroll data (audited)'],
        'payroll.loans.manage' => ['Payroll', 'Manage employee loans and advances'],
        'payroll.loans.approve' => ['Payroll', 'Approve loans and advances'],
        'payroll.benefits.manage' => ['Payroll', 'Manage benefit plans, windows and enrollments'],
        'payroll.settlement.manage' => ['Payroll', 'Prepare full and final settlements'],
        'payroll.settlement.approve' => ['Payroll', 'Approve full and final settlements'],
        'payroll.tax_declaration.review' => ['Payroll', 'Review and approve tax declarations and proofs'],
        'payroll.quality.view' => ['Payroll', 'View payroll data quality findings and action items'],
        // Employee self-service (own data only)
        'payroll.payslips.view_own' => ['Payroll', 'View and download your own payslips'],
        'payroll.compensation.view_own' => ['Payroll', 'View your own compensation summary'],
        'payroll.loans.view_own' => ['Payroll', 'View your own loans and advances'],
        'payroll.benefits.view_own' => ['Payroll', 'View your own benefits'],
        'payroll.benefits.enroll_own' => ['Payroll', 'Enroll in eligible benefits'],
        'payroll.tax_declaration.submit' => ['Payroll', 'Submit your own tax declarations and proofs'],
        'recruitment.view' => ['Recruitment', 'View assigned hiring records'],
        'recruitment.manage' => ['Recruitment', 'Manage assigned requisitions and candidates'],
        'recruitment.approve' => ['Recruitment', 'Independently approve requisitions'],
        'recruitment.confidential' => ['Recruitment', 'View confidential requisitions across assigned scope'],
        'recruitment.offer.manage' => ['Recruitment', 'Draft, version and send offers'],
        'recruitment.offer.approve' => ['Recruitment', 'Independently approve offers'],
        'recruitment.handover' => ['Recruitment', 'Convert an accepted offer into an employee record'],
        'recruitment.reports.view' => ['Recruitment', 'View recruitment pipeline analytics'],
        'recruitment.privacy.manage' => ['Recruitment', 'Anonymize expired applicant data and reveal contact details'],
        'recruitment.pipeline.manage' => ['Recruitment', 'Configure hiring pipelines and stage SLAs'],
        // People — directory and profile
        'people.directory' => ['People', 'Search the company directory (coworker basics only)'],
        'people.view' => ['People', 'View employee records within your data scope'],
        'people.manage' => ['People', 'Create and edit employee, job and personal records'],
        'people.personal.view' => ['People', 'View personal (employee-class) fields of employees'],
        'people.confidential.view' => ['People', 'View HR-confidential fields and manager-sensitive data'],
        'people.sensitive.view' => ['People', 'View masked highly sensitive data (IDs, bank)'],
        'people.sensitive.reveal' => ['People', 'Reveal unmasked highly sensitive data (audited)'],
        'people.identity.manage' => ['People', 'Manage identity and work-authorization records'],
        'people.bank.manage' => ['People', 'Manage employee bank accounts'],
        'people.documents.manage' => ['People', 'Upload and manage employee documents'],
        'people.documents.verify' => ['People', 'Verify employee documents and credentials'],
        'people.notes.manage' => ['People', 'Create HR and manager notes'],
        'people.notes.private' => ['People', 'Read private HR notes'],
        'people.separate' => ['People', 'Separate employees'],
        'people.rehire' => ['People', 'Rehire separated people'],
        'people.invite' => ['People', 'Invite employees to activate accounts'],
        'people.changes.approve' => ['People', 'Approve profile change requests'],
        'people.bulk' => ['People', 'Perform bulk employee operations'],
        'people.import' => ['People', 'Import employees'],
        'people.export' => ['People', 'Export employee data'],
        'people.export_sensitive' => ['People', 'Export HR-confidential and sensitive fields'],
        'people.settings' => ['People', 'Configure people settings, custom fields and policies'],
        'people.quality.view' => ['People', 'View people action center and data quality findings'],
        'people.quality.resolve' => ['People', 'Resolve and assign data quality findings'],
        // Attendance / time office
        'attendance.view' => ['Attendance', 'View your attendance, shifts and submit requests'],
        'attendance.team.view' => ['Attendance', 'View team attendance within your data scope'],
        'attendance.approve' => ['Attendance', 'Approve regularization, on-duty and shift swaps'],
        'attendance.overtime.approve' => ['Attendance', 'Approve overtime'],
        'attendance.manage' => ['Attendance', 'Run time-office operations'],
        'attendance.process' => ['Attendance', 'Process and recalculate attendance'],
        'attendance.adjust' => ['Attendance', 'Make manual attendance adjustments'],
        'attendance.adjust.approve' => ['Attendance', 'Approve sensitive adjustments (maker-checker)'],
        'attendance.location.view' => ['Attendance', 'View precise attendance locations'],
        'attendance.devices.manage' => ['Attendance', 'Manage attendance devices and device events'],
        'attendance.import' => ['Attendance', 'Import attendance and punches'],
        'attendance.export' => ['Attendance', 'Export attendance data'],
        'attendance.reports.view' => ['Attendance', 'View attendance reports'],
        'attendance.roster.manage' => ['Attendance', 'Edit rosters and shift assignments'],
        'attendance.roster.publish' => ['Attendance', 'Publish and unpublish rosters'],
        'attendance.policy.manage' => ['Attendance', 'Manage attendance policies, shifts and calendars'],
        'attendance.lock' => ['Attendance', 'Lock attendance periods'],
        'attendance.unlock' => ['Attendance', 'Unlock attendance periods (strict)'],
        'attendance.bulk' => ['Attendance', 'Perform bulk attendance actions'],
        'attendance.simulate' => ['Attendance', 'Simulate attendance policy changes'],
        // Leave (Phase 4 consumers) and legacy time keys retained for compatibility
        'leave.view' => ['Leave', 'View your leave balances, ledger and calendar'],
        'leave.request' => ['Leave', 'Request, withdraw and cancel your own leave'],
        'leave.team.view' => ['Leave', 'View team leave, calendar and availability within your data scope'],
        'leave.approve' => ['Leave', 'Approve leave requests'],
        'leave.manage' => ['Leave', 'Run leave operations (requests on behalf, cycles, comp-off credits)'],
        'leave.policy.manage' => ['Leave', 'Manage leave types, policies, versions and assignments'],
        'leave.adjust' => ['Leave', 'Propose manual leave balance adjustments'],
        'leave.adjust.approve' => ['Leave', 'Approve sensitive balance adjustments (maker-checker)'],
        'leave.accrual.run' => ['Leave', 'Preview and run accruals, carry-forward and expiry'],
        'leave.import' => ['Leave', 'Import opening balances and historical leave'],
        'leave.export' => ['Leave', 'Export leave data'],
        'leave.lock' => ['Leave', 'Lock leave periods'],
        'leave.unlock' => ['Leave', 'Unlock leave periods (strict)'],
        'leave.reports.view' => ['Leave', 'View leave reports and the absence command center'],
        'leave.simulate' => ['Leave', 'Run non-mutating leave policy and entitlement simulations'],
        'leave.medical.view' => ['Leave', 'View medical reasons and documents attached to leave'],
        'leave.compoff.manage' => ['Leave', 'Grant and approve comp-off credits and claims'],
        'time.configure' => ['Time administration', 'Configure shifts and policies'], 'time.lock' => ['Time administration', 'Lock and independently unlock periods'],
        'organization.view' => ['Organization', 'View organization'], 'organization.manage' => ['Organization', 'Manage organization'],
        'integrations.view' => ['Integrations', 'View integration connections and sync health'], 'integrations.manage' => ['Integrations', 'Manage integration metadata and diagnostics'],
        'security.access_reviews.view' => ['Security', 'View access review campaigns'], 'security.access_reviews.manage' => ['Security', 'Create and complete access reviews'],
        'intelligence.view' => ['Intelligence', 'View permission-filtered HR findings'],
        'users.view' => ['People & access', 'View users and roles'], 'users.manage' => ['People & access', 'Manage users and assign roles'],
        'roles.manage' => ['People & access', 'Manage permissions and roles'], 'settings.manage' => ['Administration', 'Manage company settings'],
        'lifecycle.view' => ['Lifecycle', 'View onboarding, offboarding and move cases in scope'],
        'lifecycle.manage' => ['Lifecycle', 'Create cases, assign and complete lifecycle tasks'],
        'lifecycle.templates.manage' => ['Lifecycle', 'Manage and publish checklist templates'],
        'lifecycle.moves.manage' => ['Lifecycle', 'Request transfers, promotions and manager changes'],
        'lifecycle.moves.approve' => ['Lifecycle', 'Independently approve internal moves'],
        'lifecycle.clearance.manage' => ['Lifecycle', 'Clear departmental exit items and initiate settlement'],
        'lifecycle.exit.view' => ['Lifecycle', 'Read confidential exit interviews'],
        'lifecycle.alumni.manage' => ['Lifecycle', 'Manage alumni records and rehire eligibility'],
        'lifecycle.self' => ['Lifecycle', 'Complete your own lifecycle tasks and exit interview'],
        'performance.self' => ['Performance', 'Own goals, check-ins, reviews, feedback and 1:1 notes'],
        'performance.view' => ['Performance', 'View goals and review progress of people in your data scope'],
        'performance.goals.manage' => ['Performance', 'Create and cascade goals for people in scope'],
        'performance.goals.approve' => ['Performance', 'Approve goals for people in scope'],
        'performance.cycles.manage' => ['Performance', 'Manage review cycles, templates, rating scales and competencies'],
        'performance.reviews.manage' => ['Performance', 'Write manager reviews and nominate reviewers for people in scope'],
        'performance.calibrate' => ['Performance', 'Propose calibration rating changes'],
        'performance.calibrate.approve' => ['Performance', 'Independently approve calibration changes and finalize reviews'],
        'performance.pip.manage' => ['Performance', 'Manage confidential performance improvement plans'],
        'performance.reports.view' => ['Performance', 'View performance dashboards and distributions'],
        'performance.outcomes.view' => ['Performance', 'Read finalized review outcomes (compensation and promotion inputs)'],
        'performance.feedback.reveal' => ['Performance', 'Reveal anonymous feedback authors'],
        'learning.self' => ['Learning', 'Own skills, courses, enrolments, certifications and training requests'],
        'learning.view' => ['Learning', 'View learning, skills and compliance of people in your data scope'],
        'learning.skills.manage' => ['Learning', 'Manage the skills taxonomy and role skill requirements'],
        'learning.skills.validate' => ['Learning', 'Validate or assess skill levels of people in scope'],
        'learning.catalog.manage' => ['Learning', 'Manage courses, paths, sessions, assessments and certifications'],
        'learning.enrol.manage' => ['Learning', 'Enrol people, mark attendance and record completion'],
        'learning.enrol.approve' => ['Learning', 'Approve or reject enrolments for people in scope'],
        'learning.assign.manage' => ['Learning', 'Manage mandatory training rules and assignments'],
        'learning.requests.approve' => ['Learning', 'Approve training requests and manage learning budgets'],
        'learning.reports.view' => ['Learning', 'View learning dashboards and cost reports'],
        'learning.reports.export' => ['Learning', 'Export learning reports'],
        'experience.self' => ['Employee Experience', 'Create and view own HR service tickets and employee communications'],
        'experience.view' => ['Employee Experience', 'View tickets within assigned employee data scope'],
        'experience.ticket.manage' => ['Employee Experience', 'Manage HR service tickets and view private notes'],
        'experience.ticket.assign' => ['Employee Experience', 'Assign HR service tickets within the workspace'],
        'experience.category.manage' => ['Employee Experience', 'Manage HR service catalog categories'],
        'experience.knowledge.manage' => ['Employee Experience', 'Create, version and publish HR knowledge articles'],
        'experience.announcement.manage' => ['Employee Experience', 'Create and publish employee announcements'],
        'experience.survey.manage' => ['Employee Experience', 'Create surveys and view privacy-thresholded aggregate results'],
        'experience.survey.identifiable.view' => ['Employee Experience', 'View identifiable survey responses with audited access'],
        'experience.recognition.manage' => ['Employee Experience', 'Moderate employee recognition submissions'],
        'experience.analytics.view' => ['Employee Experience', 'View tenant-wide privacy-protected Experience service metrics'],
        'assets.self' => ['Assets', 'View and acknowledge your assigned assets'],
        'assets.manage' => ['Assets', 'Manage workspace inventory and custody'],
        'expenses.self' => ['Expenses', 'Submit and view your expense claims'],
        'expenses.approve' => ['Expenses', 'Independently approve expense claims in your employee scope'],
        'expenses.manage' => ['Expenses', 'Configure expense policies and review claims'],
        'expenses.payments.confirm' => ['Expenses', 'Record externally confirmed reimbursements'],
        'travel.self' => ['Travel', 'Submit and view your travel requests'],
        'travel.approve' => ['Travel', 'Independently approve travel within your employee scope'],
        'workflows.manage' => ['Workflows', 'Design workflows and recover instances'],
        'workflows.publish' => ['Workflows', 'Independently publish workflow versions'],
        'workflows.run' => ['Workflows', 'Initiate published workflows'],
        'workflows.act' => ['Workflows', 'View and decide assigned workflow tasks'],
        'analytics.view' => ['Analytics', 'Query authorized aggregate metrics'],
        'analytics.export' => ['Analytics', 'Export authorized aggregate reports'],
        'audit.view' => ['Administration', 'View audit history'], 'work.view' => ['My Work', 'View tasks'], 'work.manage' => ['My Work', 'Create and update tasks'],
    ];

    public const MODULES = ['work', 'notifications', 'people', 'attendance', 'payroll', 'recruitment', 'leave', 'lifecycle', 'performance', 'learning', 'experience', 'assets', 'expenses', 'travel', 'workflows', 'analytics', 'integrations', 'security', 'intelligence'];

    /** Own-data payroll keys: safe for everyone with an employee record. */
    public const PAYROLL_SELF = ['payroll.payslips.view_own', 'payroll.compensation.view_own', 'payroll.loans.view_own', 'payroll.benefits.view_own', 'payroll.benefits.enroll_own', 'payroll.tax_declaration.submit'];

    private const MEMBER = ['learning.self', 'experience.self', 'performance.self', 'lifecycle.self', 'work.view', 'work.manage', 'people.view', 'people.directory', 'attendance.view', 'leave.view', 'leave.request', ...self::PAYROLL_SELF];

    private const MANAGER = ['learning.self', 'learning.view', 'learning.skills.validate', 'learning.enrol.approve', 'learning.requests.approve', 'learning.reports.view', 'experience.self', 'experience.view', 'performance.self', 'performance.view', 'performance.goals.manage', 'performance.goals.approve', 'performance.reviews.manage', 'performance.reports.view', 'lifecycle.self', 'work.view', 'work.manage', 'people.view', 'people.directory', 'attendance.view', 'attendance.team.view', 'attendance.approve', 'attendance.overtime.approve', 'attendance.reports.view', 'leave.view', 'leave.request', 'leave.team.view', 'leave.approve', 'leave.reports.view', 'people.changes.approve', 'people.quality.view', ...self::PAYROLL_SELF];

    /** HR gets everything except platform administration. */
    private const HR_EXCLUDED = ['workflows.publish', 'expenses.manage', 'expenses.payments.confirm', 'roles.manage', 'settings.manage', 'users.manage', 'recruitment.confidential', 'performance.feedback.reveal', 'experience.survey.identifiable.view'];

    /** Payroll administration keys (everything except self-service) are never part of the default HR administrator role. */
    private static function hrExcluded(): array
    {
        return [...self::HR_EXCLUDED, ...array_values(array_filter(array_keys(self::PERMISSIONS), fn ($k) => str_starts_with($k, 'payroll.') && ! in_array($k, self::PAYROLL_SELF, true)))];
    }

    /** Registers the permission catalogue (global rows) and returns key => id. */
    public static function syncCatalog(): array
    {
        foreach (self::PERMISSIONS as $key => [$group, $label]) {
            Permission::updateOrCreate(['key' => $key], compact('group', 'label'));
        }

        return Permission::pluck('id', 'key')->all();
    }

    /** Role name => [description, scope, system, permission keys]. */
    public static function defaultRoles(): array
    {
        return [
            'Workspace administrator' => ['Full access to company administration.', 'tenant', true, array_keys(self::PERMISSIONS)],
            'HR administrator' => ['People and time-office administration across the company.', 'tenant', false, array_values(array_diff(array_keys(self::PERMISSIONS), self::hrExcluded()))],
            'Manager' => ['Manage your direct reports: approvals and team attendance.', 'direct_reports', false, [...self::MANAGER, 'assets.self', 'expenses.self', 'expenses.approve', 'travel.self', 'travel.approve', 'workflows.act', 'analytics.view', 'intelligence.view']],
            'Team member' => ['Manage your own work.', 'self', false, [...self::MEMBER, 'assets.self', 'expenses.self', 'travel.self', 'workflows.act', 'intelligence.view']],
        ];
    }

    public function create(string $name, string $slug, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $slug, $email, $password) {
            $t = Tenant::create(['name' => $name, 'slug' => $slug, 'settings' => ['timezone' => 'Asia/Kolkata', 'work_week' => [1, 2, 3, 4, 5], 'hr_email' => $email, 'notifications' => true, 'modules' => self::MODULES, 'brand_name' => 'devduo']]);
            $previous = app(TenantContext::class)->id;
            app(TenantContext::class)->id = $t->id;
            try {
                $ids = self::syncCatalog();
                $admin = null;
                foreach (self::defaultRoles() as $roleName => [$description, $scope, $system, $keys]) {
                    $role = Role::create(['name' => $roleName, 'description' => $description, 'data_scope' => $scope, 'system' => $system]);
                    $role->permissions()->sync(collect($keys)->map(fn ($k) => $ids[$k])->all());
                    $admin ??= $role;
                }
                $u = new User(['name' => 'Workspace Admin', 'email' => strtolower($email), 'password' => $password]);
                $u->tenant_id = $t->id;
                $u->save();
                $u->roles()->attach($admin->id);

                return $u->refresh();
            } finally {
                app(TenantContext::class)->id = $previous;
            }
        });
    }
}
