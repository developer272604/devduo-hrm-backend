<?php

namespace App\Domain\Analytics;

use App\Domain\Tenancy\TenantClock;
use Illuminate\Support\Facades\DB;

/**
 * Remembers organization names as they are observed by analytics so a report as of an earlier date shows the
 * name that applied then. History starts at first observation: the first name seen applies to all earlier dates.
 */
final class OrgNameHistory
{
    /** @param array<int|string,string> $current id => current name */
    public static function labelsAsOf(int $tenantId, string $type, array $current, string $asOf): array
    {
        if (! $current) {
            return [];
        }
        $rows = DB::table('analytics_org_name_history')->where('tenant_id', $tenantId)->where('org_type', $type)->orderBy('effective_from')->orderBy('id')->get();
        $byOrg = $rows->groupBy('org_id');
        $labels = [];
        foreach ($current as $id => $name) {
            $history = $byOrg->get($id, collect());
            if ($history->isEmpty() || $history->last()->name !== $name) {
                DB::table('analytics_org_name_history')->insert(['tenant_id' => $tenantId, 'org_type' => $type, 'org_id' => $id, 'name' => $name,
                    'effective_from' => $history->isEmpty() ? '1970-01-01' : TenantClock::today(), 'created_at' => now(), 'updated_at' => now()]);
                $history = $history->push((object) ['name' => $name, 'effective_from' => $history->isEmpty() ? '1970-01-01' : TenantClock::today()]);
            }
            $applicable = $history->filter(fn ($h) => (string) $h->effective_from <= $asOf)->last();
            $labels[$id] = $applicable?->name ?? $history->first()->name;
        }

        return $labels;
    }
}
