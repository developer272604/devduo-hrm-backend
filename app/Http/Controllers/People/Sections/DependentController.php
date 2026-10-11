<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\People\Sections\DependentService;
use App\Domain\People\Sections\PersonSection;
use App\Domain\People\Sections\SectionRules;
use App\Domain\Tenancy\TenantClock;
use Illuminate\Database\Eloquent\Model;

/** /employees/{uuid}/dependents. End-date a dependent (PATCH effective_to) instead of deleting to keep history. */
class DependentController extends PersonSectionController
{
    protected function service(): PersonSection
    {
        return app(DependentService::class);
    }

    protected function field(): string
    {
        return 'dependent.records';
    }

    protected function group(): string
    {
        return 'dependent';
    }

    protected function rules(bool $create, ?Model $existing): array
    {
        return [
            'name' => [...SectionRules::required($create), 'string', 'max:255'],
            'relationship' => [...SectionRules::required($create), 'string', 'max:60'],
            'date_of_birth' => [...SectionRules::optional(), 'date_format:Y-m-d', 'before_or_equal:'.TenantClock::today()],
            'gender' => [...SectionRules::optional(), 'string', 'max:30'],
            'phone' => [...SectionRules::optional(), 'string', 'max:40', SectionRules::phone()],
            'email' => [...SectionRules::optional(), 'email:rfc', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
            'effective_from' => [...SectionRules::optional(), 'date_format:Y-m-d'],
            'effective_to' => [...SectionRules::optional(), 'date_format:Y-m-d'],
            'document_uuid' => [...SectionRules::optional(), 'uuid'],
            'benefit_eligibility' => [...SectionRules::optional(), SectionRules::metadata()],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Enter the dependent\'s name.',
            'relationship.required' => 'Enter how the dependent is related to the employee.',
            'date_of_birth.before_or_equal' => 'The date of birth cannot be in the future.',
            'date_of_birth.date_format' => 'Enter dates as YYYY-MM-DD.',
            'status.in' => 'The status must be active or inactive.',
            'effective_from.date_format' => 'Enter dates as YYYY-MM-DD.',
            'effective_to.date_format' => 'Enter dates as YYYY-MM-DD.',
        ];
    }

    protected function present(Model $row, array $ctx): array
    {
        return [
            'uuid' => $row->uuid, 'name' => $row->name, 'relationship' => $row->relationship, 'date_of_birth' => $this->date($row->date_of_birth), 'gender' => $row->gender,
            'phone' => $row->phone, 'email' => $row->email, 'status' => $row->status, 'effective_from' => $this->date($row->effective_from), 'effective_to' => $this->date($row->effective_to),
            'document_uuid' => $ctx['documents'][$row->document_id] ?? null, 'benefit_eligibility' => $row->benefit_eligibility,
            'created_at' => $this->instant($row->created_at), 'updated_at' => $this->instant($row->updated_at),
        ];
    }
}
