<?php

namespace App\Domain\People\Records;

use App\Domain\People\Countries\CountryConfig;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\IdentityDocument;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Government identifiers (passport, national ID, tax ID, ...). The number is encrypted at rest, indexed by a keyed blind hash
 * for duplicate detection, and only ever listed in masked form. Reveal is a separate, audited action.
 */
final class IdentityService
{
    public function __construct(private IdentityRules $rules, private CountryConfig $countries) {}

    public function list(Employee $employee): Collection
    {
        return IdentityDocument::query()->where('person_id', $employee->person_id)->orderBy('type')->orderBy('id')->get();
    }

    public function find(Employee $employee, string $uuid): IdentityDocument
    {
        return IdentityDocument::query()->where('person_id', $employee->person_id)->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Other modules (duplicate detection, imports) call this to find people holding the same identifier without decrypting.
     *
     * @return Collection<int,IdentityDocument>
     */
    public function findDuplicates(string $number, ?int $exceptPersonId = null, ?int $tenantId = null): Collection
    {
        $tenantId ??= (int) app(\App\Domain\Tenancy\TenantContext::class)->id;

        return IdentityDocument::query()->where('number_hash', IdentityHash::for($number, $tenantId))->when($exceptPersonId, fn ($q) => $q->where('person_id', '!=', $exceptPersonId))->get();
    }

    /** @param bool $warnDuplicates HR flow: another person holding the same number returns 409 unless confirm_duplicate is sent */
    public function create(User $actor, Employee $employee, array $data, bool $warnDuplicates): IdentityDocument
    {
        $country = $this->rules->country($data['country'] ?? null);
        $definition = $this->rules->type($country, (string) ($data['type'] ?? ''), 'identity');
        $number = $this->rules->number($definition, (string) ($data['number'] ?? ''));
        $this->rules->dates($data['issue_date'] ?? null, $data['expiry_date'] ?? null);
        $documentId = $this->rules->document($employee, $data['document_id'] ?? null);

        return DB::transaction(function () use ($employee, $data, $country, $definition, $number, $documentId, $warnDuplicates) {
            $hash = IdentityHash::for($number, $employee->tenant_id);
            $this->assertUnique($employee, $hash, $definition['key'], null);
            $this->warnIfDuplicate($employee, $number, $warnDuplicates && empty($data['confirm_duplicate']));
            $row = IdentityDocument::query()->create([
                'person_id' => $employee->person_id, 'type' => $definition['key'], 'country' => $country, 'number_encrypted' => Crypt::encryptString($number), 'number_hash' => $hash,
                'number_last4' => IdentityHash::last4($number), 'issue_date' => $data['issue_date'] ?? null, 'expiry_date' => $data['expiry_date'] ?? null, 'verification_status' => 'unverified', 'document_id' => $documentId,
            ]);
            RecordLog::audit('created', $employee, 'identity', ['record' => $row->uuid, 'type' => $row->type, 'country' => $country]);
            RecordLog::event('IdentityChanged', $employee, 'Identity record added ('.($definition['label'] ?? $row->type).').', 'hr_confidential', ['identity_document', $row->id], ['operation' => 'created', 'type' => $row->type]);

            return $row;
        });
    }

    public function update(User $actor, Employee $employee, IdentityDocument $row, array $data, bool $warnDuplicates): IdentityDocument
    {
        $country = $this->rules->country($data['country'] ?? $row->country);
        $typeKey = (string) ($data['type'] ?? $row->type);
        $definition = $this->rules->type($country, $typeKey, 'identity');
        $numberChanged = isset($data['number']) && $data['number'] !== '';
        $retypes = $country !== $row->country || $typeKey !== $row->type;
        $number = null;
        if ($numberChanged) {
            $number = $this->rules->number($definition, (string) $data['number']);
        } elseif ($retypes) {
            $number = $this->rules->number($definition, $this->decrypt($row)); // re-validate the stored number against the new type
        }
        $issue = array_key_exists('issue_date', $data) ? $data['issue_date'] : $row->issue_date?->toDateString();
        $expiry = array_key_exists('expiry_date', $data) ? $data['expiry_date'] : $row->expiry_date?->toDateString();
        $this->rules->dates($issue, $expiry);
        $documentId = array_key_exists('document_id', $data) ? $this->rules->document($employee, $data['document_id']) : $row->document_id;

        return DB::transaction(function () use ($employee, $row, $data, $country, $definition, $number, $numberChanged, $issue, $expiry, $documentId, $warnDuplicates) {
            $fields = ['type' => $definition['key'], 'country' => $country, 'issue_date' => $issue, 'expiry_date' => $expiry, 'document_id' => $documentId];
            if ($number !== null) {
                $hash = IdentityHash::for($number, $employee->tenant_id);
                $this->assertUnique($employee, $hash, $definition['key'], $row->id);
                if ($numberChanged) {
                    $this->warnIfDuplicate($employee, $number, $warnDuplicates && empty($data['confirm_duplicate']));
                }
                $fields += ['number_encrypted' => Crypt::encryptString($number), 'number_hash' => $hash, 'number_last4' => IdentityHash::last4($number)];
            }
            if ($numberChanged) {
                $fields += ['verification_status' => 'unverified', 'verified_by' => null, 'verified_at' => null]; // a changed number has not been checked
            }
            $row->fill($fields)->save();
            RecordLog::audit('updated', $employee, 'identity', ['record' => $row->uuid, 'type' => $row->type, 'number_changed' => $numberChanged]);
            RecordLog::event('IdentityChanged', $employee, 'Identity record updated ('.($definition['label'] ?? $row->type).').', 'hr_confidential', ['identity_document', $row->id], ['operation' => 'updated', 'type' => $row->type]);

            return $row->refresh();
        });
    }

    public function delete(User $actor, Employee $employee, IdentityDocument $row): void
    {
        DB::transaction(function () use ($employee, $row) {
            $row->delete();
            RecordLog::audit('deleted', $employee, 'identity', ['record' => $row->uuid, 'type' => $row->type]);
            RecordLog::event('IdentityChanged', $employee, 'Identity record removed.', 'hr_confidential', ['identity_document', $row->id], ['operation' => 'deleted', 'type' => $row->type]);
        });
    }

    public function verify(User $actor, Employee $employee, IdentityDocument $row, string $status): IdentityDocument
    {
        return DB::transaction(function () use ($actor, $employee, $row, $status) {
            $reset = $status === 'unverified';
            $row->update(['verification_status' => $status, 'verified_by' => $reset ? null : $actor->id, 'verified_at' => $reset ? null : now()]);
            RecordLog::audit($reset ? 'verification_reset' : $status, $employee, 'identity', ['record' => $row->uuid, 'type' => $row->type]);
            if (! $reset) {
                RecordLog::event('IdentityVerified', $employee, 'Identity record '.$status.'.', 'hr_confidential', ['identity_document', $row->id], ['type' => $row->type, 'status' => $status]);
            }

            return $row->refresh();
        });
    }

    /** Returns the full number. Always audited; the number itself never enters the audit context. */
    public function reveal(User $actor, Employee $employee, IdentityDocument $row): string
    {
        $number = $this->decrypt($row);
        RecordLog::audit('revealed', $employee, 'identity', ['record' => $row->uuid, 'type' => $row->type]);

        return $number;
    }

    public function present(IdentityDocument $row): array
    {
        $definition = $this->countries->identifierType($row->country, $row->type) ?? [];

        return ['uuid' => $row->uuid, 'type' => $row->type, 'type_label' => $definition['label'] ?? $row->type, 'country' => $row->country,
            'number_masked' => Masking::fromLast4($row->number_last4, $definition['mask'] ?? 'last4'),
            'issue_date' => $row->issue_date?->toDateString(), 'expiry_date' => $row->expiry_date?->toDateString(), 'expiry_status' => Expiry::status($row->expiry_date),
            'verification_status' => $row->verification_status, 'verified_at' => $row->verified_at?->toIso8601String(),
            'verified_by' => $row->verified_by ? User::query()->whereKey($row->verified_by)->value('name') : null,
            'document_id' => $this->rules->documentUuid($row->document_id), 'created_at' => $row->created_at?->toIso8601String(), 'updated_at' => $row->updated_at?->toIso8601String()];
    }

    private function decrypt(IdentityDocument $row): string
    {
        try {
            return Crypt::decryptString($row->number_encrypted);
        } catch (DecryptException) {
            abort(500, 'This record cannot be decrypted. Contact your administrator.');
        }
    }

    private function assertUnique(Employee $employee, string $hash, string $type, ?int $exceptId): void
    {
        $exists = IdentityDocument::query()->where('person_id', $employee->person_id)->where('number_hash', $hash)->where('type', $type)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['number' => 'This identifier is already on record for the employee.']);
        }
    }

    private function warnIfDuplicate(Employee $employee, string $number, bool $warn): void
    {
        if ($warn && $this->findDuplicates($number, $employee->person_id, $employee->tenant_id)->isNotEmpty()) {
            abort(response()->json(['message' => 'This identifier is already recorded for another person. Review it before saving.', 'code' => 'possible_duplicate'], 409));
        }
    }
}
