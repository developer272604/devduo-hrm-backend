<?php

namespace App\Http\Controllers\Payroll\Outputs;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Payslips\PayslipService;
use App\Jobs\Payroll\GeneratePayslipsJob;
use Illuminate\Http\Request;

final class PayslipController
{
    public function generate(Request $r, string $uuid)
    {
        $run = PayrollRun::where('uuid', $uuid)->firstOrFail();
        abort_unless(in_array($run->status, PayslipService::LOCKED_RUN, true), 422, 'Payslips can only be generated from a locked payroll run.');
        GeneratePayslipsJob::dispatch($run->tenant_id, $run->id, $r->user()->id);

        return response()->json(['queued' => true, 'run_uuid' => $run->uuid], 202);
    }

    public function index(Request $r, string $uuid, PayslipService $svc)
    {
        $run = PayrollRun::where('uuid', $uuid)->firstOrFail();
        $scope = app(DataScope::class)->constrain(\App\Domain\People\Models\Employee::query(), $r->user(), 'payroll.payslips.manage')->select('employees.id');
        Audit::record('payslips.listed', 'payroll', $run->uuid);

        return Payslip::where('run_id', $run->id)->whereIn('employee_id', $scope)->with('employee.person')->orderBy('employee_id')->orderByDesc('version')->paginate(min(100, (int) $r->query('per_page', 50)))
            ->through(fn ($p) => $svc->summary($p) + ['employee_uuid' => $p->employee->uuid, 'employee_name' => $p->employee->person->display_name]);
    }

    public function publish(Request $r, string $uuid, PayslipService $svc)
    {
        $run = PayrollRun::where('uuid', $uuid)->firstOrFail();

        return ['published' => $svc->publishRun($run, $r->user())];
    }

    private function find(Request $r, string $uuid): Payslip
    {
        $p = Payslip::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($r->user(), $p->employee_id, 'payroll.payslips.manage'), 404);

        return $p;
    }

    public function publishOne(Request $r, string $uuid, PayslipService $svc)
    {
        return $svc->summary($svc->publish($this->find($r, $uuid), $r->user()));
    }

    public function show(Request $r, string $uuid, PayslipService $svc)
    {
        $p = $this->find($r, $uuid);
        $svc->log($p, $r->user(), 'viewed');
        Audit::record('payslip.viewed', 'payroll', $p->uuid);

        return $svc->summary($p, true);
    }

    public function withdraw(Request $r, string $uuid, PayslipService $svc)
    {
        $d = $r->validate(['reason' => 'required|string|max:300']);

        return $svc->summary($svc->withdraw($this->find($r, $uuid), $r->user(), $d['reason']));
    }

    public function accessLog(Request $r, string $uuid)
    {
        $p = $this->find($r, $uuid);

        return ['data' => \Illuminate\Support\Facades\DB::table('payslip_access_logs')->where('tenant_id', $p->tenant_id)->where('payslip_id', $p->id)->orderByDesc('id')->limit(200)->get(['action', 'user_id', 'ip', 'created_at'])];
    }
}
