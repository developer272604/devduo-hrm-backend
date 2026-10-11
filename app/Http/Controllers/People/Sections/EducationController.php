<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\People\Models\Employee;
use App\Domain\People\Sections\EducationService;
use App\Domain\People\Sections\PersonSection;
use App\Domain\People\Sections\SectionRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** /employees/{uuid}/education and /education/{id}/verify */
class EducationController extends PersonSectionController
{
    protected function service(): PersonSection
    {
        return app(EducationService::class);
    }

    protected function field(): string
    {
        return 'education.records';
    }

    protected function group(): string
    {
        return 'education';
    }

    protected function rules(bool $create, ?Model $existing): array
    {
        return [
            'institution' => [...SectionRules::required($create), 'string', 'max:255'],
            'qualification' => [...SectionRules::required($create), 'string', 'max:255'],
            'field_of_study' => [...SectionRules::optional(), 'string', 'max:255'],
            'start_date' => [...SectionRules::optional(), 'date_format:Y-m-d'],
            'end_date' => [...SectionRules::optional(), 'date_format:Y-m-d'],
            'grade' => [...SectionRules::optional(), 'string', 'max:60'],
            'country' => [...SectionRules::optional(), 'regex:/^[A-Za-z]{2}$/'],
            'document_uuid' => [...SectionRules::optional(), 'uuid'],
            'notes' => [...SectionRules::optional(), 'string', 'max:2000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'institution.required' => 'Enter the name of the school, college or university.',
            'qualification.required' => 'Enter the qualification, for example BSc or MBA.',
            'country.regex' => 'Enter the country as a two-letter code, for example IN.',
            'start_date.date_format' => 'Enter dates as YYYY-MM-DD.',
            'end_date.date_format' => 'Enter dates as YYYY-MM-DD.',
        ];
    }

    protected function prepare(Request $r, Employee $employee, array $data): array
    {
        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }

        return $data;
    }

    protected function present(Model $row, array $ctx): array
    {
        return [
            'uuid' => $row->uuid, 'institution' => $row->institution, 'qualification' => $row->qualification, 'field_of_study' => $row->field_of_study,
            'start_date' => $this->date($row->start_date), 'end_date' => $this->date($row->end_date), 'grade' => $row->grade, 'country' => $row->country,
            'document_uuid' => $ctx['documents'][$row->document_id] ?? null, 'notes' => $row->notes,
            'verification' => $this->verification($row, $ctx['verifiers']),
            'created_at' => $this->instant($row->created_at), 'updated_at' => $this->instant($row->updated_at),
        ];
    }
}
