<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\People\Sections\EmergencyContactService;
use App\Domain\People\Sections\PersonSection;
use App\Domain\People\Sections\SectionRules;
use Illuminate\Database\Eloquent\Model;

/** /employees/{uuid}/emergency-contacts */
class EmergencyContactController extends PersonSectionController
{
    protected function service(): PersonSection
    {
        return app(EmergencyContactService::class);
    }

    protected function field(): string
    {
        return 'emergency.records';
    }

    protected function group(): string
    {
        return 'emergency';
    }

    protected function rules(bool $create, ?Model $existing): array
    {
        return [
            'name' => [...SectionRules::required($create), 'string', 'max:255'],
            'relationship' => [...SectionRules::required($create), 'string', 'max:60'],
            'phone' => [...SectionRules::required($create), 'string', 'max:40', SectionRules::phone()],
            'alternate_phone' => [...SectionRules::optional(), 'string', 'max:40', SectionRules::phone()],
            'email' => [...SectionRules::optional(), 'email:rfc', 'max:255'],
            'address' => [...SectionRules::optional(), 'string', 'max:1000'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Enter the name of the emergency contact.',
            'relationship.required' => 'Enter how this person is related to the employee.',
            'phone.required' => 'Enter a phone number we can reach this person on.',
            'email.email' => 'Enter a valid email address.',
        ];
    }

    protected function present(Model $row, array $ctx): array
    {
        return [
            'uuid' => $row->uuid, 'name' => $row->name, 'relationship' => $row->relationship, 'phone' => $row->phone, 'alternate_phone' => $row->alternate_phone,
            'email' => $row->email, 'address' => $row->address, 'is_primary' => (bool) $row->is_primary,
            'created_at' => $this->instant($row->created_at), 'updated_at' => $this->instant($row->updated_at),
        ];
    }
}
