<?php

namespace Tests\Concerns;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Events\DomainEvent;
use App\Domain\People\Models\Employee;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Actors for the profile-section tests (all in tenant "alpha"):
 *
 *   admin      Workspace administrator, tenant scope, every permission (no employee record)
 *   boss       Manager role (direct reports): line manager of emp
 *   emp        Team member: the subject; self-service actor
 *   peer       Team member with no relationship to emp (cannot reach emp at all)
 *   hrLimited  tenant-wide HR: people.manage, personal + confidential view, notes.manage; NO notes.private, NO sensitive access
 *   hrFull     hrLimited + people.notes.private + people.sensitive.reveal + people.documents.verify
 */
trait SectionsFixtures
{
    use PeopleFixtures;

    protected Employee $boss;

    protected Employee $emp;

    protected Employee $peer;

    protected Employee $hrLimited;

    protected Employee $hrFull;

    protected function setUpSections(): void
    {
        $this->bootTenant();
        $this->boss = $this->hire('Boss One', ['role' => 'Manager']);
        $this->emp = $this->hire('Asha Rao', ['manager' => $this->boss, 'role' => 'Team member']);
        $this->peer = $this->hire('Pete Peer', ['role' => 'Team member', 'department' => 1]);
        $limited = ['people.view', 'people.manage', 'people.personal.view', 'people.confidential.view', 'people.notes.manage'];
        $this->hrLimited = $this->hire('Hal Limited', ['role' => 'Team member']);
        $this->hrLimited->person->user->roles()->attach($this->customRole($limited)->id);
        $this->hrFull = $this->hire('Hera Full', ['role' => 'Team member']);
        $this->hrFull->person->user->roles()->attach($this->customRole([...$limited, 'people.notes.private', 'people.sensitive.reveal', 'people.documents.verify'])->id);
    }

    protected function customRole(array $permissions, string $scope = 'tenant'): Role
    {
        $role = Role::create(['name' => 'Custom '.uniqid(), 'description' => 'Test role', 'data_scope' => $scope, 'system' => false]);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id')->all());

        return $role;
    }

    /** Signs in as the user behind an employee (or a User) and returns $this for chaining. */
    protected function as(Employee|User $who): static
    {
        return $this->signIn($who instanceof Employee ? $who->person->user : $who);
    }

    protected function path(string $suffix, ?Employee $subject = null): string
    {
        return '/api/v1/employees/'.($subject ?? $this->emp)->uuid.'/'.ltrim($suffix, '/');
    }

    protected function inTenant(): void
    {
        $this->context($this->admin->tenant_id); // the tenant middleware clears the context after each request
    }

    protected function assertAudited(string $action, ?Employee $subject = null): AuditLog
    {
        $this->inTenant();
        $row = AuditLog::where('action', $action)->where('resource', 'employee:'.($subject ?? $this->emp)->uuid)->latest('id')->first();
        $this->assertNotNull($row, "Missing audit entry $action");

        return $row;
    }

    protected function assertEvent(string $type, ?Employee $subject = null): DomainEvent
    {
        $this->inTenant();
        $event = DomainEvent::where('type', $type)->where('employee_id', ($subject ?? $this->emp)->id)->latest('id')->first();
        $this->assertNotNull($event, "Missing domain event $type");

        return $event;
    }

    protected function secondTenant(): User
    {
        return app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
    }
}
