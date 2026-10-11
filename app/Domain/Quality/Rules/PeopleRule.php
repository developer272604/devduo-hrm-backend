<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Quality\QualityRule;
use App\Domain\Tenancy\TenantClock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared helpers for the People quality rules: current employments, current assignment join, id-chunked streaming. */
abstract class PeopleRule implements QualityRule
{
    /** Employments that are on the books (not separated, inactive or archived). */
    public const CURRENT = ['preboarding', 'active', 'probation', 'notice_period', 'suspended', 'long_leave'];

    /** employees e + people p + assignment a effective today, restricted to the tenant and current employments. */
    protected function employees(int $tenantId, ?array $statuses = self::CURRENT, bool $withAssignment = false): Builder
    {
        $q = DB::table('employees as e')->join('people as p', function ($j) {
            $j->on('p.id', '=', 'e.person_id')->whereNull('p.deleted_at');
        })->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at');
        if ($statuses !== null) {
            $q->whereIn('e.status', $statuses);
        }
        if ($withAssignment) {
            $today = TenantClock::today();
            $q->leftJoin('employee_assignments as a', function ($j) use ($today) {
                $j->on('a.employee_id', '=', 'e.id')->where('a.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $today));
            });
        }

        return $q;
    }

    /**
     * Streams rows in id order, 500 at a time, so a large tenant never sits in memory. The query must expose the key as `id`.
     *
     * @return \Generator<int,object>
     */
    protected function stream(Builder $query, string $idColumn = 'e.id', int $size = 500): \Generator
    {
        $last = 0;
        while (true) {
            $rows = (clone $query)->where($idColumn, '>', $last)->orderBy($idColumn)->limit($size)->get();
            if ($rows->isEmpty()) {
                return;
            }
            foreach ($rows as $row) {
                yield $row;
            }
            $last = $rows->last()->id;
        }
    }

    protected function today(): string
    {
        return TenantClock::today();
    }

    protected function name(int $employeeId): string
    {
        return (string) DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $employeeId)->value('people.display_name');
    }

    /** @param array<int,string> $items */
    protected function list(array $items): string
    {
        return implode(', ', $items);
    }
}
