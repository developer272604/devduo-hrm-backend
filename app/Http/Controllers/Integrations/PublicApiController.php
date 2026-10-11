<?php

namespace App\Http\Controllers\Integrations;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Versioned, read-only public API (v1). Field privacy is fixed by design: only the fields below are ever returned.
 * No date of birth, personal contact, identity, bank, compensation, medical or free-text data is exposed.
 */
final class PublicApiController
{
    public function employees(Request $request)
    {
        $tenantId = app(TenantContext::class)->id;
        $today = now()->toDateString();
        $withEmail = in_array('employees:email', $request->attributes->get('api_client')->scopes ?? [], true);
        $query = DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->leftJoin('employee_assignments as a', function ($j) use ($today) {
                $j->on('a.employee_id', '=', 'e.id')->whereRaw('a.id = (select a2.id from employee_assignments a2 where a2.employee_id = e.id and a2.effective_from <= ? and (a2.effective_to is null or a2.effective_to >= ?) order by a2.effective_from desc, a2.id desc limit 1)', [$today, $today]);
            })
            ->leftJoin('departments as d', 'd.id', '=', 'a.department_id')
            ->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at');
        if ($status = $request->query('status')) {
            $query->where('e.status', (string) $status);
        } else {
            $query->whereIn('e.status', ['active', 'probation', 'notice_period', 'suspended', 'long_leave']);
        }
        if ($updated = $request->query('updated_since')) {
            $query->where('e.updated_at', '>=', $updated);
        }
        $page = $query->orderBy('e.id')->select(['e.uuid', 'e.employee_number', 'p.display_name', 'e.status', 'e.joined_on', 'd.code as department_code', 'e.work_email'])
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => ['id' => $r->uuid, 'employee_number' => $r->employee_number, 'display_name' => $r->display_name, 'status' => $r->status,
                'joined_on' => substr((string) $r->joined_on, 0, 10), 'department_code' => $r->department_code] + ($withEmail ? ['work_email' => $r->work_email] : []))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function departments(Request $request)
    {
        $page = DB::table('departments')->where('tenant_id', app(TenantContext::class)->id)->orderBy('id')->select(['code', 'name', 'active'])->paginate($this->perPage($request));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'active' => (bool) $r->active])->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 25)));
    }
}
