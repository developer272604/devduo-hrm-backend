<?php
namespace Database\Seeders;

use App\Domain\People\Models\Employee;
use App\Domain\People\Models\Person;
use App\Domain\People\Services\EmployeeService;
use App\Domain\Tenancy\TenantRunner;
use App\Domain\Organization\OrganizationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Repeatable local preview data, created through the same domain service as the UI. */
class PeopleDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local')) throw new \RuntimeException('Local demo only.');
        $tenant=Tenant::where('slug','devduo')->firstOrFail();
        app(TenantRunner::class)->run($tenant->id,function () use ($tenant) {
            $admin=User::where('tenant_id',$tenant->id)->where('email','admin@devduo.test')->firstOrFail();
            $organization=[];
            foreach(['legal_entity_id'=>['legal-entities','Devduo Studio','DD-DEMO-ENTITY'],'location_id'=>['locations','Chennai','DD-DEMO-CHN'],'department_id'=>['departments','Engineering','DD-DEMO-ENG'],'designation_id'=>['designations','Engineer','DD-DEMO-ENGINEER'],'employee_type_id'=>['employee-types','Permanent','DD-DEMO-PERM']] as $field=>[$type,$name,$code]) {
                $organization[$field]=OrganizationRecord::forType($type)->newQuery()->firstOrCreate(['code'=>$code],['name'=>$name,'active'=>true,'metadata'=>['country'=>'IN','timezone'=>'Asia/Kolkata']])->id;
            }
            if (!Employee::where('employee_number','DD-DEMO-001')->exists() && !Person::where('user_id',$admin->id)->exists()) {
                app(EmployeeService::class)->create(['person'=>['legal_first_name'=>'Workspace','legal_last_name'=>'Admin'],'employment'=>['employee_number'=>'DD-DEMO-001','worker_type'=>'employee','joined_on'=>'2026-01-05','country'=>'IN','timezone'=>'Asia/Kolkata','work_email'=>'admin@devduo.test'],'assignment'=>$organization,'user_id'=>$admin->id]);
            }
        });
    }
}
