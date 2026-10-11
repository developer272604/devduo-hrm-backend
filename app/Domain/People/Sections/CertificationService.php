<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeCertification;
use App\Domain\People\Models\EmployeeSkill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CertificationService extends PersonSection
{
    /** Days before expiry at which a certification is reported as "expiring soon". */
    public const EXPIRING_DAYS = 60;

    private const VERIFIED_FACTS = ['name', 'issuer', 'issue_date', 'expiry_date', 'credential_id', 'credential_url', 'document_id'];

    protected function modelClass(): string
    {
        return EmployeeCertification::class;
    }

    protected function label(): string
    {
        return 'Certification';
    }

    protected function eventStem(): string
    {
        return 'Certification';
    }

    protected function classification(): string
    {
        return Classification::EMPLOYEE;
    }

    protected function datePairs(): array
    {
        return [['issue_date', 'expiry_date', 'The expiry date cannot be before the issue date.']];
    }

    protected function descriptor(Model $row): ?string
    {
        return $row->name;
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id');
    }

    protected function creating(Employee $employee, array $data): array
    {
        return $data + ['verification_status' => 'unverified'];
    }

    protected function updating(Employee $employee, Model $row, array $data): array
    {
        return $this->resetVerification($row, $data, self::VERIFIED_FACTS);
    }

    protected function beforeRemove(Employee $employee, Model $row): void
    {
        // Skills may point at the certification; the skill stays, the link goes.
        EmployeeSkill::query()->where('person_id', $employee->person_id)->where('certification_id', $row->id)->update(['certification_id' => null]);
    }

    /** valid | expiring_soon | expired | no_expiry */
    public function expiryStatus(?string $expiry): string
    {
        if (! $expiry) {
            return 'no_expiry';
        }
        $today = $this->today();
        if ($expiry < $today) {
            return 'expired';
        }

        return $expiry <= date('Y-m-d', strtotime($today.' +'.self::EXPIRING_DAYS.' days')) ? 'expiring_soon' : 'valid';
    }
}
