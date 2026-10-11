<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Operations\{MyAttendance, TeamAttendance, CommandCenter, Ops};
use App\Domain\Attendance\Capture\{PunchService, PunchPresenter};
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AttendanceReadController extends Controller
{
    public function mine(Request $r, MyAttendance $service) { return $service->summary($r->user()); }

    public function team(Request $r, TeamAttendance $service)
    {
        $data = $r->validate(['date'=>'nullable|date_format:Y-m-d']);
        return $service->today($r->user(), $data['date'] ?? null);
    }

    public function commandCenter(Request $r, CommandCenter $service)
    {
        $data = $r->validate(['date'=>'nullable|date_format:Y-m-d']);
        return $service->build($r->user(), $data['date'] ?? null);
    }

    public function days(Request $r)
    {
        $data = $r->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from','status'=>'nullable|string|max:40','employee'=>'nullable|uuid']);
        $scope = Ops::employees($r->user(), ['attendance.view','attendance.team.view','attendance.manage','attendance.reports.view']);
        if (!empty($data['employee'])) $scope->where('e.uuid',$data['employee']);
        $page = AttendanceDay::query()->whereIn('employee_id',$scope->select('e.id'))->with('employee.person')
            ->whereBetween('attendance_date',[$data['from'],$data['to']])
            ->when(!empty($data['status']),fn($q)=>$q->where('status',$data['status']))->orderByDesc('attendance_date')->orderBy('employee_id')->paginate(25);
        return $page->through(fn($day)=>$this->present($day));
    }

    public function show(Request $r, string $uuid)
    {
        $scope = Ops::employees($r->user(), ['attendance.view','attendance.team.view','attendance.manage','attendance.reports.view']);
        $day = AttendanceDay::query()->whereIn('employee_id',$scope->select('e.id'))->where('uuid',$uuid)->with('employee.person')->firstOrFail();
        // Do not expose raw input snapshots: location data has its own privacy-aware presenter.
        $trace=$day->traces()->reorder()->orderByDesc('calculation_version')->first();
        return $this->present($day)+['explanation'=>$trace ? json_decode($trace->explanation,true) : null];
    }

    public function punch(Request $r, PunchService $service, PunchPresenter $presenter)
    {
        $data=$r->validate(['direction'=>'required|in:IN,OUT','idempotency_key'=>'required|string|max:100']);
        $own=Ops::ownEmployee($r->user());
        abort_unless($own,422,'Link an employee record to your account before recording attendance.');
        $result=$service->record(['employee'=>Employee::query()->findOrFail($own->id),'source'=>'web','direction'=>$data['direction'],'idempotency_key'=>$data['idempotency_key'],'actor'=>$r->user(),'ip'=>$r->ip()]);
        return response()->json(['event'=>$presenter->present($result->event,$r->user()),'duplicate'=>$result->duplicate],$result->duplicate ? 200 : 201);
    }

    private function present(AttendanceDay $day): array
    {
        return $day->only(['uuid','attendance_date','status','confidence','finalization','calculation_version','first_in','last_out','net_minutes','required_minutes','late_minutes','early_exit_minutes','overtime_minutes','approved_overtime_minutes','missing_punch'])
            + ['employee_uuid'=>$day->employee->uuid,'employee_name'=>$day->employee->person->display_name];
    }
}
