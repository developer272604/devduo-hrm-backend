<?php

namespace App\Domain\People\Records;

use App\Domain\Audit\Audit;
use App\Domain\Security\Models\LegalHold;
use App\Domain\Security\Models\RetentionPolicy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class LetterRetention
{
    public const CATEGORY = 'hr_issued_letters';

    public function run(bool $apply): array
    {
        $tenantId = app(TenantContext::class)->id ?? throw new \LogicException('Tenant context is required.');

        return DB::transaction(function () use ($apply, $tenantId) {
            // Serialize retention with legal hold placement and retention policy edits for this tenant.
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $policy = RetentionPolicy::query()->where('module', 'people')->where('data_category', self::CATEGORY)
                ->where('enabled', true)->where('action', 'purge')->first();
            if (! $policy) {
                return ['status' => 'no_enabled_purge_policy', 'eligible' => 0, 'held' => 0, 'purged' => 0];
            }

            $holds = LegalHold::query()->whereNull('released_at')
                ->where(fn ($q) => $q->whereNull('module')->orWhere('module', 'people'))->pluck('subject_ref');
            $wholeModuleHeld = $holds->containsStrict(null);
            $subjects = array_fill_keys($holds->filter()->map(fn (string $ref) => strtolower($ref))->all(), true);
            $eligible = $held = $purged = 0;
            $cutoff = now()->subDays($policy->retention_days);
            DB::table('hr_issued_letters as letters')
                ->join('employees', 'employees.id', '=', 'letters.employee_id')
                ->where('letters.tenant_id', $tenantId)->where('employees.tenant_id', $tenantId)
                ->where('letters.created_at', '<', $cutoff)
                ->select('letters.id', 'letters.uuid', 'employees.uuid as employee_uuid')
                ->chunkById(100, function ($letters) use ($wholeModuleHeld, $subjects, $apply, $tenantId, &$eligible, &$held, &$purged) {
                    $ids = [];
                    foreach ($letters as $letter) {
                        if ($wholeModuleHeld
                            || isset($subjects['letter:'.strtolower($letter->uuid)]) || isset($subjects['employee:'.strtolower($letter->employee_uuid)])) {
                            $held++;
                        } else {
                            $ids[] = $letter->id;
                        }
                    }
                    $eligible += count($ids);
                    if ($apply && $ids !== []) {
                        $purged += DB::table('hr_issued_letters')->where('tenant_id', $tenantId)->whereIn('id', $ids)->delete();
                    }
                }, 'letters.id', 'id');

            if ($purged > 0) {
                Audit::record('hr_letter.retention_purged', 'people', 'retention_policy:'.$policy->uuid, [
                    'count' => $purged, 'retention_days' => $policy->retention_days, 'cutoff' => $cutoff->toIso8601String(),
                ]);
            }

            return ['status' => $apply ? 'applied' : 'preview', 'eligible' => $eligible, 'held' => $held, 'purged' => $purged];
        }, 3);
    }
}
