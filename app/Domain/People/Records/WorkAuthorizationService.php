<?php

namespace App\Domain\People\Records;

use App\Domain\People\Countries\CountryConfig;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\WorkAuthorization;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Visas, work and residence permits. The (optional) number is encrypted and listed masked, like identity records. */
final class WorkAuthorizationService
{
    public function __construct(private IdentityRules $rules, private CountryConfig $countries) {}

    public function list(Employee $employee): Collection
    {
        return WorkAuthorization::query()->where('person_id', $employee->person_id)->orderByDesc('expiry_date')->orderBy('id')->get();
    }

    public function find(Employee $employee, string $uuid): WorkAuthorization
    {
        return WorkAuthorization::query()->where('person_id', $employee->person_id)->where('uuid', $uuid)->firstOrFail();
    }

    public function create(User $actor, Employee $employee, array $data): WorkAuthorization
    {
        $country = $this->rules->country($data['country'] ?? null);
        $definition = $this->rules->type($country, (string) ($data['type'] ?? ''), 'work_authorization');
        $number = $this->number($definition, $data['number'] ?? null);
        $this->rules->dates($data['issue_date'] ?? null, $data['expiry_date'] ?? null);
        $documentId = $this->rules->document($employee, $data['document_id'] ?? null);

        return DB::transaction(function () use ($employee, $data, $country, $definition, $number, $documentId) {
            $row = WorkAuthorization::query()->create([
                'person_id' => $employee->person_id, 'type' => $definition['key'], 'country' => $country, 'number_encrypted' => $number !== null ? Crypt::encryptString($number) : null,
                'number_last4' => $number !== null ? IdentityHash::last4($number) : null, 'sponsor' => $data['sponsor'] ?? null, 'issue_date' => $data['issue_date'] ?? null, 'expiry_date' => $data['expiry_date'] ?? null,
                'status' => $data['status'] ?? 'active', 'verification_status' => 'unverified', 'document_id' => $documentId,
            ]);
            RecordLog::audit('created', $employee, 'work_authorization', ['record' => $row->uuid, 'type' => $row->type, 'country' => $country]);
            RecordLog::event('WorkAuthorizationChanged', $employee, 'Work authorization added ('.($definition['label'] ?? $row->type).').', 'hr_confidential', ['work_authorization', $row->id], ['operation' => 'created', 'type' => $row->type]);

            return $row;
        });
    }

    public function update(User $actor, Employee $employee, WorkAuthorization $row, array $data): WorkAuthorization
    {
        $country = $this->rules->country($data['country'] ?? $row->country);
        $typeKey = (string) ($data['type'] ?? $row->type);
        $definition = $this->rules->type($country, $typeKey, 'work_authorization');
        $numberGiven = array_key_exists('number', $data);
        $number = $numberGiven ? $this->number($definition, $data['number']) : null;
        if (! $numberGiven && ($country !== $row->country || $typeKey !== $row->type) && $row->number_encrypted) {
            $number = $this->number($definition, $this->decrypt($row)); // re-validate against the new type
        }
        $issue = array_key_exists('issue_date', $data) ? $data['issue_date'] : $row->issue_date?->toDateString();
        $expiry = array_key_exists('expiry_date', $data) ? $data['expiry_date'] : $row->expiry_date?->toDateString();
        $this->rules->dates($issue, $expiry);
        $documentId = array_key_exists('document_id', $data) ? $this->rules->document($employee, $data['document_id']) : $row->document_id;

        return DB::transaction(function () use ($employee, $row, $data, $country, $definition, $number, $numberGiven, $issue, $expiry, $documentId) {
            $fields = ['type' => $definition['key'], 'country' => $country, 'issue_date' => $issue, 'expiry_date' => $expiry, 'document_id' => $documentId];
            foreach (['sponsor', 'status'] as $plain) {
                if (array_key_exists($plain, $data)) {
                    $fields[$plain] = $data[$plain];
                }
            }
            if ($numberGiven || $number !== null) {
                $fields += ['number_encrypted' => $number !== null ? Crypt::encryptString($number) : null, 'number_last4' => $number !== null ? IdentityHash::last4($number) : null];
            }
            if ($numberGiven) {
                $fields += ['verification_status' => 'unverified', 'verified_by' => null, 'verified_at' => null];
            }
            $row->fill($fields)->save();
            RecordLog::audit('updated', $employee, 'work_authorization', ['record' => $row->uuid, 'type' => $row->type, 'number_changed' => $numberGiven]);
            RecordLog::event('WorkAuthorizationChanged', $employee, 'Work authorization updated ('.($definition['label'] ?? $row->type).').', 'hr_confidential', ['work_authorization', $row->id], ['operation' => 'updated', 'type' => $row->type]);

            return $row->refresh();
        });
    }

    public function delete(User $actor, Employee $employee, WorkAuthorization $row): void
    {
        DB::transaction(function () use ($employee, $row) {
            $row->delete();
            RecordLog::audit('deleted', $employee, 'work_authorization', ['record' => $row->uuid, 'type' => $row->type]);
            RecordLog::event('WorkAuthorizationChanged', $employee, 'Work authorization removed.', 'hr_confidential', ['work_authorization', $row->id], ['operation' => 'deleted', 'type' => $row->type]);
        });
    }

    public function verify(User $actor, Employee $employee, WorkAuthorization $row, string $status): WorkAuthorization
    {
        return DB::transaction(function () use ($actor, $employee, $row, $status) {
            $reset = $status === 'unverified';
            $row->update(['verification_status' => $status, 'verified_by' => $reset ? null : $actor->id, 'verified_at' => $reset ? null : now()]);
            RecordLog::audit($reset ? 'verification_reset' : $status, $employee, 'work_authorization', ['record' => $row->uuid, 'type' => $row->type]);
            if (! $reset) {
                RecordLog::event('WorkAuthorizationVerified', $employee, 'Work authorization '.$status.'.', 'hr_confidential', ['work_authorization', $row->id], ['type' => $row->type, 'status' => $status]);
            }

            return $row->refresh();
        });
    }

    public function reveal(User $actor, Employee $employee, WorkAuthorization $row): ?string
    {
        $number = $row->number_encrypted ? $this->decrypt($row) : null;
        RecordLog::audit('revealed', $employee, 'work_authorization', ['record' => $row->uuid, 'type' => $row->type]);

        return $number;
    }

    public function present(WorkAuthorization $row): array
    {
        $definition = $this->countries->identifierType($row->country, $row->type, 'work_authorization') ?? [];
        $expiry = Expiry::status($row->expiry_date);

        return ['uuid' => $row->uuid, 'type' => $row->type, 'type_label' => $definition['label'] ?? $row->type, 'country' => $row->country,
            'number_masked' => $row->number_last4 ? Masking::fromLast4($row->number_last4, $definition['mask'] ?? 'last4') : null, 'sponsor' => $row->sponsor,
            'issue_date' => $row->issue_date?->toDateString(), 'expiry_date' => $row->expiry_date?->toDateString(), 'expiry_status' => $expiry,
            'status' => $row->status === 'active' && $expiry === 'expired' ? 'expired' : $row->status, 'verification_status' => $row->verification_status, 'verified_at' => $row->verified_at?->toIso8601String(),
            'verified_by' => $row->verified_by ? User::query()->whereKey($row->verified_by)->value('name') : null, 'document_id' => $this->rules->documentUuid($row->document_id),
            'created_at' => $row->created_at?->toIso8601String(), 'updated_at' => $row->updated_at?->toIso8601String()];
    }

    private function number(array $definition, ?string $raw): ?string
    {
        return $raw === null || trim($raw) === '' ? null : $this->rules->number($definition, $raw);
    }

    private function decrypt(WorkAuthorization $row): string
    {
        try {
            return Crypt::decryptString($row->number_encrypted);
        } catch (DecryptException) {
            abort(500, 'This record cannot be decrypted. Contact your administrator.');
        }
    }
}
