<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ONE reusable resolver for polymorphic, effective-dated assignments (shift_assignments, work_week_assignments,
 * employee_holiday_calendar_assignments). Pure ranking logic lives in pick(); fetch() loads candidate rows in one query.
 *
 * An employee "context" is the as-of-date set of organization attributes keyed by target type, produced by OrgContext:
 *   ['employee' => '12', 'team' => '3', 'department' => '4', 'employee_group' => ['1','7'], 'business_unit' => ..., 'location' => ...,
 *    'employee_type' => ..., 'legal_entity' => ..., 'country' => 'IN', 'tenant' => '*']
 */
final class AssignmentResolver
{
    /** True when an assignment row targets the context on $date. @param array<string,mixed> $row */
    public static function matches(array $row, array $context, string $date): bool
    {
        if ($row['effective_from'] > $date || ($row['effective_to'] !== null && $row['effective_to'] < $date)) {
            return false;
        }
        $type = $row['target_type'];
        if ($type === 'tenant') {
            return true;
        }
        $value = $context[$type] ?? null;
        if ($value === null) {
            return false;
        }
        if ($type === 'employee_group') {
            return in_array((string) $row['target_id'], array_map('strval', (array) $value), true);
        }
        if ($type === 'country') {
            return strtoupper((string) $value) === strtoupper((string) $row['target_id']);
        }

        return (string) $value === (string) $row['target_id'];
    }

    /** Sort key: higher wins. */
    public static function sortKey(array $row): array
    {
        return [Specificity::rank($row['target_type']), (int) (bool) $row['is_override'], (int) $row['priority'], $row['effective_from'], (int) $row['id']];
    }

    /**
     * Picks the winning assignment for the context/date.
     *
     * @param  array<int,array<string,mixed>>  $rows  candidate rows (any date)
     * @return array{row:array<string,mixed>,ambiguous:bool,tied_ids:array<int,int>}|null
     */
    public static function pick(array $rows, array $context, string $date): ?array
    {
        $matching = array_values(array_filter($rows, fn ($r) => self::matches($r, $context, $date)));
        if (! $matching) {
            return null;
        }
        usort($matching, fn ($a, $b) => self::sortKey($b) <=> self::sortKey($a));
        $top = $matching[0];
        $tied = array_values(array_filter($matching, fn ($r) => array_slice(self::sortKey($r), 0, 4) === array_slice(self::sortKey($top), 0, 4)));

        return ['row' => $top, 'ambiguous' => count($tied) > 1, 'tied_ids' => array_map(fn ($r) => (int) $r['id'], $tied)];
    }

    /**
     * Loads every assignment row of a table that could apply to the given targets inside [from, to] (one query).
     *
     * @param  array<string,array<int,string>>  $targets  type => distinct target ids (see OrgContext::targets)
     * @return array<int,array<string,mixed>>
     */
    public static function fetch(string $table, string $from, string $to, array $targets, array $columns = ['*']): array
    {
        $tenant = app(TenantContext::class)->id;
        $query = DB::table($table)->where('tenant_id', $tenant)->where('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->where(function ($q) use ($targets) {
                $q->where('target_type', 'tenant');
                foreach ($targets as $type => $ids) {
                    if ($type !== 'tenant' && $ids) {
                        $q->orWhere(fn ($w) => $w->where('target_type', $type)->whereIn('target_id', array_map('strval', array_values($ids))));
                    }
                }
            });

        return $query->get($columns)->map(fn ($r) => (array) $r)->all();
    }

    /** Short human description of an assignment for resolution notes, e.g. "department Engineering, priority 0". */
    public static function describe(array $row, ?string $targetName): string
    {
        $who = Specificity::label($row['target_type']).($row['target_type'] === 'tenant' ? '' : ' '.($targetName ?? '#'.$row['target_id']));
        $bits = [$who, 'priority '.(int) $row['priority']];
        if (! empty($row['is_override'])) {
            $bits[] = 'override';
        }

        return implode(', ', $bits);
    }
}
