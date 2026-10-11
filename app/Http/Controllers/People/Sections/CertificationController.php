<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\People\Sections\CertificationService;
use App\Domain\People\Sections\PersonSection;
use App\Domain\People\Sections\SectionRules;
use Illuminate\Database\Eloquent\Model;

/** /employees/{uuid}/certifications and /certifications/{id}/verify */
class CertificationController extends PersonSectionController
{
    protected function service(): PersonSection
    {
        return app(CertificationService::class);
    }

    protected function field(): string
    {
        return 'certification.records';
    }

    protected function group(): string
    {
        return 'certification';
    }

    protected function rules(bool $create, ?Model $existing): array
    {
        return [
            'name' => [...SectionRules::required($create), 'string', 'max:255'],
            'issuer' => [...SectionRules::optional(), 'string', 'max:255'],
            'issue_date' => [...SectionRules::optional(), 'date_format:Y-m-d'],
            'expiry_date' => [...SectionRules::optional(), 'date_format:Y-m-d'],
            'credential_id' => [...SectionRules::optional(), 'string', 'max:120'],
            'credential_url' => [...SectionRules::optional(), 'url:http,https', 'max:500'],
            'document_uuid' => [...SectionRules::optional(), 'uuid'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Enter the name of the certification.',
            'credential_url.url' => 'Enter a valid web address starting with http:// or https://.',
            'issue_date.date_format' => 'Enter dates as YYYY-MM-DD.',
            'expiry_date.date_format' => 'Enter dates as YYYY-MM-DD.',
        ];
    }

    protected function present(Model $row, array $ctx): array
    {
        $expiry = $this->date($row->expiry_date);

        return [
            'uuid' => $row->uuid, 'name' => $row->name, 'issuer' => $row->issuer, 'issue_date' => $this->date($row->issue_date), 'expiry_date' => $expiry,
            'expiry_status' => app(CertificationService::class)->expiryStatus($expiry), 'credential_id' => $row->credential_id, 'credential_url' => $row->credential_url,
            'document_uuid' => $ctx['documents'][$row->document_id] ?? null, 'verification' => $this->verification($row, $ctx['verifiers']),
            'created_at' => $this->instant($row->created_at), 'updated_at' => $this->instant($row->updated_at),
        ];
    }
}
