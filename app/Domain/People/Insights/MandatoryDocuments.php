<?php

namespace App\Domain\People\Insights;

use Illuminate\Support\Facades\DB;

/**
 * Which mandatory document types (document_types.mandatory) apply to an employee (document_types.mandatory_for =
 * {countries:[], employee_types:[]}; empty or missing = applies to everyone) and which of them have no uploaded,
 * non-rejected document. Set-based: one query for the types, one per 500 people for their documents.
 */
final class MandatoryDocuments
{
    /**
     * @param  array<int,object>  $rows  objects with id, person_id, country, employee_type_id
     * @return array<int,array<int,string>> employee id => names of missing mandatory document types (only employees with something missing)
     */
    public function missing(int $tenantId, array $rows): array
    {
        $types = DB::table('document_types')->where('tenant_id', $tenantId)->where('mandatory', true)->where('active', true)->get(['id', 'name', 'mandatory_for']);
        if ($types->isEmpty() || ! $rows) {
            return [];
        }
        $have = [];
        foreach (array_chunk(array_values(array_unique(array_map(fn ($r) => $r->person_id, $rows))), 500) as $people) {
            foreach (DB::table('employee_documents')->where('tenant_id', $tenantId)->whereIn('person_id', $people)->whereIn('document_type_id', $types->pluck('id'))->whereNull('deleted_at')
                ->where('verification_status', '!=', 'rejected')->get(['person_id', 'document_type_id']) as $d) {
                $have[$d->person_id.':'.$d->document_type_id] = true;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            foreach ($types as $type) {
                if ($this->applies($type->mandatory_for, $row) && ! isset($have[$row->person_id.':'.$type->id])) {
                    $out[$row->id][] = $type->name;
                }
            }
        }

        return $out;
    }

    private function applies(?string $json, object $row): bool
    {
        $rule = $json ? (json_decode($json, true) ?: []) : [];
        $countries = array_map('strtoupper', (array) ($rule['countries'] ?? []));
        $employeeTypes = array_map('strval', (array) ($rule['employee_types'] ?? []));

        return (! $countries || in_array(strtoupper((string) $row->country), $countries, true))
            && (! $employeeTypes || in_array((string) $row->employee_type_id, $employeeTypes, true));
    }
}
