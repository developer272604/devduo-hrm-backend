<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\People\Sections\ContactService;
use App\Domain\People\Sections\PersonSection;
use App\Domain\People\Sections\SectionRules;
use Illuminate\Database\Eloquent\Model;

/** Email and phone records: /employees/{uuid}/contacts */
class ContactController extends PersonSectionController
{
    protected function service(): PersonSection
    {
        return app(ContactService::class);
    }

    protected function field(): string
    {
        return 'contact.records';
    }

    protected function group(): string
    {
        return 'contact';
    }

    protected function rules(bool $create, ?Model $existing): array
    {
        $kind = $existing?->kind ?? request()->input('kind');
        $value = [...SectionRules::required($create), 'string', 'max:255'];
        $value[] = $kind === 'phone' ? SectionRules::phone() : 'email:rfc';

        return [
            'kind' => $create ? ['required', 'in:email,phone'] : ['prohibited'],
            'label' => [...SectionRules::required($create), 'in:work,personal,home,mobile,other'],
            'value' => $value,
            'country_code' => [...SectionRules::optional(), 'regex:/^\+?[0-9]{1,4}$/'],
            'is_primary' => ['sometimes', 'boolean'],
            'effective_from' => [...SectionRules::optional(), 'date_format:Y-m-d'],
            'effective_to' => [...SectionRules::optional(), 'date_format:Y-m-d'],
        ];
    }

    protected function messages(): array
    {
        return [
            'kind.required' => 'Choose whether this is an email address or a phone number.',
            'kind.in' => 'The contact type must be email or phone.',
            'kind.prohibited' => 'The type of a contact cannot be changed. Add a new contact instead.',
            'label.in' => 'The label must be work, personal, home, mobile or other.',
            'value.required' => 'Enter the email address or phone number.',
            'value.email' => 'Enter a valid email address.',
            'country_code.regex' => 'Enter the country calling code, for example +91.',
            'effective_from.date_format' => 'Enter dates as YYYY-MM-DD.',
            'effective_to.date_format' => 'Enter dates as YYYY-MM-DD.',
        ];
    }

    protected function present(Model $row, array $ctx): array
    {
        return [
            'uuid' => $row->uuid, 'kind' => $row->kind, 'label' => $row->label, 'value' => $row->value, 'country_code' => $row->country_code,
            'is_primary' => (bool) $row->is_primary, 'verified' => $row->verified_at !== null, 'verified_at' => $this->instant($row->verified_at),
            'effective_from' => $this->date($row->effective_from), 'effective_to' => $this->date($row->effective_to),
            'is_current' => $row->effective_to === null || $this->date($row->effective_to) >= \App\Domain\Tenancy\TenantClock::today(),
            'created_at' => $this->instant($row->created_at), 'updated_at' => $this->instant($row->updated_at),
        ];
    }
}
