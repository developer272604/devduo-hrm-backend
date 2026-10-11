<?php

namespace App\Domain\People\Services;

use Illuminate\Support\Facades\DB;

/**
 * Finds likely duplicate people/employees. It only ever *warns*; nothing is merged automatically.
 * Government-identifier matching uses the HMAC blind index (identity_documents.number_hash) when supplied.
 */
final class DuplicateDetector
{
    /**
     * @param  array{work_email?:?string,personal_email?:?string,personal_phone?:?string,employee_number?:?string,legal_name?:?string,date_of_birth?:?string,identifier_hashes?:array<int,string>}  $candidate
     * @return array<int,array{reason:string,confidence:string,person_uuid:string,employee_uuid:?string,name:string,employee_number:?string}>
     */
    public function find(array $candidate, ?int $ignorePersonId = null): array
    {
        $tenant = app(\App\Domain\Tenancy\TenantContext::class)->id;
        $matches = [];
        $add = function (string $reason, string $confidence, $row) use (&$matches) {
            $key = $row->person_uuid.'|'.$reason;
            $matches[$key] = ['reason' => $reason, 'confidence' => $confidence, 'person_uuid' => $row->person_uuid, 'employee_uuid' => $row->employee_uuid ?? null, 'name' => $row->name, 'employee_number' => $row->employee_number ?? null];
        };
        $base = fn () => DB::table('people')->leftJoin('employees', function ($j) {
            $j->on('employees.person_id', '=', 'people.id')->whereNull('employees.deleted_at');
        })->where('people.tenant_id', $tenant)->whereNull('people.deleted_at')->when($ignorePersonId, fn ($q) => $q->where('people.id', '!=', $ignorePersonId))
            ->select('people.uuid as person_uuid', 'employees.uuid as employee_uuid', 'people.display_name as name', 'employees.employee_number');

        if ($email = $this->email($candidate['work_email'] ?? null)) {
            foreach ($base()->whereRaw('LOWER(employees.work_email) = ?', [$email])->get() as $row) {
                $add('Same work email', 'high', $row);
            }
        }
        if ($email = $this->email($candidate['personal_email'] ?? null)) {
            foreach ($base()->whereRaw('LOWER(people.personal_email) = ?', [$email])->get() as $row) {
                $add('Same personal email', 'high', $row);
            }
        }
        if (($digits = $this->digits($candidate['personal_phone'] ?? null)) && strlen($digits) >= 7) {
            foreach ($base()->whereNotNull('people.personal_phone')->get() as $row) {
                if ($this->digits(DB::table('people')->where('uuid', $row->person_uuid)->value('personal_phone')) === $digits) {
                    $add('Same personal phone', 'medium', $row);
                }
            }
        }
        if ($number = trim($candidate['employee_number'] ?? '')) {
            foreach ($base()->where('employees.employee_number', $number)->get() as $row) {
                $add('Same employee number', 'high', $row);
            }
        }
        if (! empty($candidate['legal_name']) && ! empty($candidate['date_of_birth'])) {
            foreach ($base()->whereRaw('LOWER(people.legal_name) = ?', [mb_strtolower(trim($candidate['legal_name']))])->where('people.date_of_birth', $candidate['date_of_birth'])->get() as $row) {
                $add('Same name and date of birth', 'high', $row);
            }
        }
        foreach ($candidate['identifier_hashes'] ?? [] as $hash) {
            $rows = DB::table('identity_documents')->join('people', 'people.id', '=', 'identity_documents.person_id')->leftJoin('employees', 'employees.person_id', '=', 'people.id')
                ->where('identity_documents.tenant_id', $tenant)->where('identity_documents.number_hash', $hash)->when($ignorePersonId, fn ($q) => $q->where('people.id', '!=', $ignorePersonId))
                ->select('people.uuid as person_uuid', 'employees.uuid as employee_uuid', 'people.display_name as name', 'employees.employee_number')->get();
            foreach ($rows as $row) {
                $add('Same government identifier', 'high', $row);
            }
        }

        return array_values($matches);
    }

    private function email(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_strtolower($value);
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
