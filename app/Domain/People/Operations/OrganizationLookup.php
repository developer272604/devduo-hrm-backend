<?php

namespace App\Domain\People\Operations;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Resolves organization names/codes to ids inside the current tenant, with "did you mean" suggestions. */
final class OrganizationLookup
{
    /** @var array<string,array<int,object>> */
    private array $cache = [];

    private function rows(string $table): array
    {
        return $this->cache[$table] ??= DB::table($table)->where('tenant_id', app(TenantContext::class)->id)->get(['id', 'name', 'code', 'active', 'metadata'])->all();
    }

    /** @return array{0:?object,1:?string} [record, error message] */
    public function find(string $table, string $label, string $value): array
    {
        $needle = mb_strtolower(trim($value));
        $rows = $this->rows($table);
        $hits = array_values(array_filter($rows, fn ($r) => mb_strtolower($r->code) === $needle));
        if (! $hits) {
            $hits = array_values(array_filter($rows, fn ($r) => mb_strtolower($r->name) === $needle));
        }
        if (count($hits) > 1) {
            return [null, $label." '".$value."' matches more than one record. Use the code instead."];
        }
        if ($hits) {
            return $hits[0]->active ? [$hits[0], null] : [null, $label." '".$value."' exists but is inactive."];
        }
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($rows as $r) {
            if (! $r->active) {
                continue;
            }
            foreach ([$r->name, $r->code] as $candidate) {
                $d = levenshtein($needle, mb_strtolower($candidate));
                if ($d < $bestDistance) {
                    $bestDistance = $d;
                    $best = $candidate;
                }
            }
        }
        $hint = $best !== null && $bestDistance <= max(2, intdiv(mb_strlen($needle), 3)) ? " Did you mean '".$best."'?" : '';

        return [null, $label." '".$value."' was not found.".$hint];
    }

    public static function metadata(object $record, string $key): ?string
    {
        $meta = $record->metadata ? json_decode($record->metadata, true) : [];

        return $meta[$key] ?? null;
    }
}
