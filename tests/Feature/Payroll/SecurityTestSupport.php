<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Contracts\ResultStore as ResultStoreContract;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Results\ResultDraft;
use App\Domain\People\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Shared helpers for the workstream D payroll operations tests (use with PayrollFixtures + PeopleFixtures). */
trait SecurityTestSupport
{
    protected function userWithPermissions(string $email, array $permissions): User
    {
        $this->context($this->admin->tenant_id);
        $role = Role::create(['name' => 'Role '.$email, 'description' => 'x', 'data_scope' => 'tenant', 'system' => false]);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id')->all());
        $user = new User(['name' => $email, 'email' => $email, 'password' => 'StrongPassword123']);
        $user->tenant_id = $this->admin->tenant_id;
        $user->save();
        $user->roles()->attach($role->id);

        return $user->refresh();
    }

    protected function mapToGroup(Employee $employee, $group, string $from = '2026-01-01'): void
    {
        DB::table('employee_policy_references')->insert(['tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id, 'kind' => 'payroll_group', 'reference_id' => $group->id,
            'effective_from' => $from, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<int,array<string,mixed>>|null $components */
    protected function calc(PayrollRun $run, Employee $employee, ?array $components = null, array $o = []): PayrollCalculationResult
    {
        $re = DB::table('payroll_run_employees')->where('run_id', $run->id)->where('employee_id', $employee->id)->first();
        $reId = $re ? $re->id : $this->runEmployee($run, $employee)->id;

        return app(ResultStoreContract::class)->record(new ResultDraft($reId, 'INR', $components ?? $this->sampleComponents(), '1.0.0', $o['payable'] ?? '30', '30', trace: ['payable_days' => 'attendance']));
    }
}
