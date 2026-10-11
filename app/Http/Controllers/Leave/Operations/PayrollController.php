<?php

namespace App\Http\Controllers\Leave\Operations;

use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Payroll\PayrollContract;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Payroll contract for Phase 5: unit totals + references + cutoff metadata. No money, ever. */
class PayrollController extends Controller
{
    private const READ = ['leave.manage', 'leave.reports.view'];

    public function cutoff(Request $r, PayrollContract $c)
    {
        $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);

        return $c->cutoff($r->input('from'), $r->input('to'));
    }

    public function contract(Request $r, PayrollContract $c)
    {
        $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);

        return $c->preview($r->input('from'), $r->input('to'), $this->scope($r));
    }

    public function publish(Request $r, PayrollContract $c)
    {
        $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d', 'batch_key' => 'nullable|string|max:120']);

        return response()->json($c->publish($r->input('from'), $r->input('to'), $this->scope($r), $r->input('batch_key')), 201);
    }

    public function batch(Request $r, PayrollContract $c, string $key)
    {
        return $c->batch($key, $this->scope($r)) ?? abort(404, 'That payroll batch does not exist.');
    }

    /** @return array<int,int> employees the caller may reach (data scope) */
    private function scope(Request $r): array
    {
        return LeaveOps::employees($r->user(), self::READ)->pluck('e.id')->map(fn ($v) => (int) $v)->all();
    }
}
