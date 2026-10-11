<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Access\Classification;
use App\Domain\Quality\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Country-specific expectations from config('hrm_countries.<CC>') (plus the tenant's country_configurations override of
 * identifier_types by key). Only explicit flags create findings, so a country without flags - or no config at all - yields none:
 *   identifier_types[].required = true (kind identity)  or  required_identifiers = [keys]  -> an identity document of that type must exist
 *   bank_required = true                                  -> an active bank account must exist
 * Statutory field values have no storage in Phase 2, so statutory expectations are not checked.
 */
final class CountryRequiredInfoRule extends PeopleRule
{
    public function key(): string
    {
        return 'country_required_information';
    }

    public function category(): string
    {
        return 'country_compliance';
    }

    public function label(): string
    {
        return 'Incomplete country-required information';
    }

    /** @return array{identifiers:array<string,string>,bank:bool} */
    public static function requirements(int $tenantId, string $country): array
    {
        $pack = (array) config('hrm_countries.'.strtoupper($country), []);
        $types = [];
        foreach ((array) ($pack['identifier_types'] ?? []) as $t) {
            $types[$t['key'] ?? ''] = $t;
        }
        $override = DB::table('country_configurations')->where('tenant_id', $tenantId)->where('country', strtoupper($country))->value('identifier_types');
        foreach ((array) json_decode((string) $override, true) as $t) {
            if (isset($t['key'])) {
                $types[$t['key']] = array_merge($types[$t['key']] ?? [], $t);
            }
        }
        $ids = [];
        foreach ($types as $key => $t) {
            if (($t['kind'] ?? 'identity') === 'identity' && ! empty($t['required']) && empty($t['disabled'])) {
                $ids[$key] = $t['label'] ?? $key;
            }
        }
        foreach ((array) ($pack['required_identifiers'] ?? []) as $key) {
            $ids[$key] ??= $types[$key]['label'] ?? $key;
        }

        return ['identifiers' => $ids, 'bank' => ! empty($pack['bank_required'])];
    }

    public function scan(int $tenantId): iterable
    {
        $q = $this->employees($tenantId)->select(['e.id as id', 'e.person_id', 'e.country']);
        $cache = [];
        foreach ($this->stream($q) as $row) {
            $req = $cache[$row->country] ??= self::requirements($tenantId, (string) $row->country);
            if (! $req['identifiers'] && ! $req['bank']) {
                continue;
            }
            $missing = [];
            if ($req['identifiers']) {
                $have = DB::table('identity_documents')->where('tenant_id', $tenantId)->where('person_id', $row->person_id)->pluck('type')->all();
                foreach ($req['identifiers'] as $key => $label) {
                    if (! in_array($key, $have, true)) {
                        $missing[] = $label;
                    }
                }
            }
            if ($req['bank'] && ! DB::table('employee_bank_accounts')->where('tenant_id', $tenantId)->where('person_id', $row->person_id)->where('status', 'active')->exists()) {
                $missing[] = 'Bank account';
            }
            if ($missing) {
                yield new Finding((int) $row->id, 'emp:'.$row->id, 'Missing information required in '.$row->country.': '.$this->list($missing), 'Country rules expect this information on file.', 'warning',
                    ['employee', (int) $row->id], ['country' => $row->country, 'missing' => $missing], Classification::HR_CONFIDENTIAL);
            }
        }
    }
}
