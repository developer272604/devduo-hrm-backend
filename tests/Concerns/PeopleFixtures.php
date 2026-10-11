<?php

namespace Tests\Concerns;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Organization\OrganizationRecord;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\EmployeeService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\User;

/**
 * Shared test fixtures for People and Attendance suites.
 *
 *   $this->bootTenant()                  creates tenant "alpha" + admin, sets tenant context, builds org records
 *   $this->org['department'][0]           organization record ids (see buildOrganization)
 *   $this->hire('Asha', ['manager'=>$e])  creates an employee (and optionally a login with a role)
 *   $this->signIn($user)                  authenticates the next requests and keeps the tenant context
 *
 * Time is frozen at 2026-10-07 10:00 (tenant timezone Asia/Kolkata) so effective-date rules are deterministic.
 */
trait PeopleFixtures
{
    protected User $admin;

    /** @var array<string,array<int,int>> */
    protected array $org = [];

    protected function bootTenant(string $slug = 'alpha'): User
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 7)->setTime(4, 30)); // 10:00 in Asia/Kolkata
        $this->admin = app(WorkspaceProvisioner::class)->create(ucfirst($slug), $slug, "admin@$slug.test", 'StrongPassword123');
        $this->context($this->admin->tenant_id);
        $this->buildOrganization();

        return $this->admin;
    }

    protected function context(?int $tenantId): void
    {
        app(TenantContext::class)->id = $tenantId;
    }

    protected function signIn(User $user): static
    {
        $user->refresh();
        $this->actingAs($user)->withSession(['version' => $user->session_version]);
        $this->context($user->tenant_id);

        return $this;
    }

    protected function buildOrganization(): void
    {
        $make = function (string $type, string $name, string $code, array $metadata = []) {
            return OrganizationRecord::forType($type)->newQuery()->create(['name' => $name, 'code' => $code, 'active' => true, 'metadata' => $metadata])->id;
        };
        $this->org = [
            'legal_entity' => [$make('legal-entities', 'Alpha India Pvt Ltd', 'ALPHA-IN', ['country' => 'IN']), $make('legal-entities', 'Alpha UK Ltd', 'ALPHA-UK', ['country' => 'GB'])],
            'location' => [$make('locations', 'Chennai', 'CHN', ['country' => 'IN', 'timezone' => 'Asia/Kolkata']), $make('locations', 'London', 'LON', ['country' => 'GB', 'timezone' => 'Europe/London'])],
            'department' => [$make('departments', 'Engineering', 'ENG'), $make('departments', 'Sales', 'SAL'), $make('departments', 'People', 'HR')],
            'designation' => [$make('designations', 'Engineer', 'ENGR'), $make('designations', 'Senior Engineer', 'SENG'), $make('designations', 'Manager', 'MGR')],
            'employee_type' => [$make('employee-types', 'Permanent', 'PERM'), $make('employee-types', 'Contractor', 'CTR')],
            'business_unit' => [$make('business-units', 'Product', 'PRD')],
            'team' => [$make('teams', 'Platform', 'PLT')],
        ];
    }

    /**
     * Creates an employee through the real service. Options: manager (Employee), role (role name, creates a login),
     * joined_on, department (index), location (index), email, status, assignment (extra assignment ids).
     */
    protected function hire(string $name, array $o = []): Employee
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);
        $user = null;
        if (isset($o['role'])) {
            $user = $this->userWithRole($o['role'], $o['email'] ?? strtolower($first).'@alpha.test', $name);
        }
        $location = $o['location'] ?? 0;
        $employee = app(EmployeeService::class)->create([
            'person' => ['legal_first_name' => $first, 'legal_last_name' => $last, 'date_of_birth' => $o['dob'] ?? null, 'personal_email' => $o['personal_email'] ?? null],
            'employment' => ['worker_type' => 'employee', 'joined_on' => $o['joined_on'] ?? '2026-01-05', 'work_email' => $o['work_email'] ?? strtolower($first).'.'.strtolower($last ?? 'x').'@alpha.test', 'status' => $o['status'] ?? null],
            'assignment' => ($o['assignment'] ?? []) + ['legal_entity_id' => $this->org['legal_entity'][$location], 'location_id' => $this->org['location'][$location], 'department_id' => $this->org['department'][$o['department'] ?? 0],
                'designation_id' => $this->org['designation'][0], 'employee_type_id' => $this->org['employee_type'][0]],
            'manager_id' => isset($o['manager']) ? $o['manager']->id : null, 'user_id' => $user?->id, 'confirm_duplicates' => true,
        ]);

        return $employee;
    }

    protected function userWithRole(string $roleName, string $email, string $name = 'User'): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => 'StrongPassword123']);
        $user->tenant_id = app(TenantContext::class)->id;
        $user->save();
        $user->roles()->attach(Role::where('name', $roleName)->firstOrFail()->id);

        return $user->refresh();
    }
}
