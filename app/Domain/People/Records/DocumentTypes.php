<?php

namespace App\Domain\People\Records;

use App\Domain\People\Models\DocumentType;
use Illuminate\Database\QueryException;

/** Per-tenant document type catalogue with lazily seeded defaults. */
final class DocumentTypes
{
    /** code => [name, category, requires_expiry, classification, mandatory] */
    public const DEFAULTS = [
        'passport' => ['Passport', 'identity', true, 'highly_sensitive', false],
        'national_id' => ['National ID', 'identity', true, 'highly_sensitive', false],
        'address_proof' => ['Address proof', 'identity', false, 'hr_confidential', false],
        'offer_letter' => ['Offer letter', 'employment', false, 'hr_confidential', false],
        'employment_contract' => ['Employment contract', 'contract', false, 'hr_confidential', false],
        'education_certificate' => ['Education certificate', 'education', false, 'hr_confidential', false],
        'experience_letter' => ['Experience letter', 'experience', false, 'hr_confidential', false],
        'professional_certificate' => ['Professional certificate', 'certificate', true, 'hr_confidential', false],
        'policy_acknowledgement' => ['Policy acknowledgement', 'policy', false, 'employee', false],
        'visa' => ['Visa', 'visa', true, 'highly_sensitive', false],
        'work_permit' => ['Work permit', 'work_permit', true, 'highly_sensitive', false],
        'tax_declaration' => ['Tax declaration', 'tax', false, 'highly_sensitive', false],
        'bank_proof' => ['Bank proof', 'bank', false, 'highly_sensitive', false],
        'other' => ['Other document', 'other', false, 'hr_confidential', false],
    ];

    /**
     * Seeds the default types the tenant does not have yet. Idempotent: existing rows (including ones an administrator
     * deactivated or edited) are never touched, and types are deactivated rather than deleted, so nothing is resurrected.
     */
    public function ensureDefaults(): void
    {
        $have = DocumentType::query()->pluck('code')->all();
        foreach (self::DEFAULTS as $code => [$name, $category, $expiry, $classification, $mandatory]) {
            if (in_array($code, $have, true)) {
                continue;
            }
            try {
                DocumentType::query()->create(['code' => $code, 'name' => $name, 'category' => $category, 'requires_expiry' => $expiry, 'classification' => $classification, 'mandatory' => $mandatory, 'active' => true]);
            } catch (QueryException) {
                // A concurrent request seeded the same code; nothing to do.
            }
        }
    }

    public function present(DocumentType $type): array
    {
        return ['code' => $type->code, 'name' => $type->name, 'category' => $type->category, 'requires_expiry' => $type->requires_expiry, 'mandatory' => $type->mandatory,
            'mandatory_for' => $type->mandatory_for ?: ['countries' => [], 'employee_types' => []], 'classification' => $type->classification, 'active' => $type->active, 'default' => isset(self::DEFAULTS[$type->code])];
    }

    /** Active mandatory document types that apply to the employee (country and employee-type filters). */
    public function requiredFor(string $country, ?int $employeeTypeId): \Illuminate\Support\Collection
    {
        return DocumentType::query()->where('active', true)->where('mandatory', true)->orderBy('name')->get()->filter(function (DocumentType $type) use ($country, $employeeTypeId) {
            $rule = $type->mandatory_for ?: [];
            $countries = array_map('strtoupper', $rule['countries'] ?? []);
            $types = array_map('intval', $rule['employee_types'] ?? []);

            return (! $countries || in_array(strtoupper($country), $countries, true)) && (! $types || ($employeeTypeId !== null && in_array($employeeTypeId, $types, true)));
        })->values();
    }
}
