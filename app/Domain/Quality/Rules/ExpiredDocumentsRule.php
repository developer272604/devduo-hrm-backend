<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Access\Classification;
use App\Domain\Quality\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Expired documents, identity documents, work authorizations and certifications of people who are currently employed.
 * Expiry is judged against the tenant's today; "expiring soon" is an Action Center insight, not a finding.
 * Findings never contain document or ID numbers.
 */
final class ExpiredDocumentsRule extends PeopleRule
{
    public function key(): string
    {
        return 'expired_document';
    }

    public function category(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return 'Expired documents and authorizations';
    }

    public function scan(int $tenantId): iterable
    {
        $today = $this->today();
        $current = fn () => DB::table('employees as e')->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at')->whereIn('e.status', self::CURRENT);

        // Employee documents (a document may be tied to one employment or to the person).
        $q = $current()->join('employee_documents as d', function ($j) {
            $j->on('d.person_id', '=', 'e.person_id')->whereNull('d.deleted_at')->where(fn ($w) => $w->whereNull('d.employee_id')->orWhereColumn('d.employee_id', 'e.id'));
        })->join('document_types as t', 't.id', '=', 'd.document_type_id')->where('d.tenant_id', $tenantId)->whereNotNull('d.expiry_date')->where('d.expiry_date', '<', $today)
            ->where('d.verification_status', '!=', 'rejected')->select(['d.id as id', 'e.id as employee_id', 'd.title', 'd.expiry_date', 't.name as type_name', 't.classification']);
        foreach ($this->stream($q, 'd.id') as $row) {
            $class = Classification::valid((string) $row->classification) ? $row->classification : Classification::HR_CONFIDENTIAL;
            yield new Finding((int) $row->employee_id, 'doc:'.$row->id, 'Expired document: '.$row->type_name, $row->title.' expired on '.substr($row->expiry_date, 0, 10).'.', 'warning',
                ['employee_document', (int) $row->id], ['kind' => 'document', 'type' => $row->type_name, 'expired_on' => substr($row->expiry_date, 0, 10)], $class);
        }

        // Identity documents (passport, national ID, ...).
        $q = $current()->join('identity_documents as i', 'i.person_id', '=', 'e.person_id')->where('i.tenant_id', $tenantId)->whereNotNull('i.expiry_date')->where('i.expiry_date', '<', $today)
            ->select(['i.id as id', 'e.id as employee_id', 'i.type', 'i.country', 'i.expiry_date']);
        foreach ($this->stream($q, 'i.id') as $row) {
            yield new Finding((int) $row->employee_id, 'idn:'.$row->id, 'Expired identity document: '.str_replace('_', ' ', $row->type), 'Expired on '.substr($row->expiry_date, 0, 10).'.', 'action_required',
                ['identity_document', (int) $row->id], ['kind' => 'identity', 'type' => $row->type, 'country' => $row->country, 'expired_on' => substr($row->expiry_date, 0, 10)], Classification::HIGHLY_SENSITIVE);
        }

        // Work authorizations (visa, work permit): the legally most urgent expiry.
        $q = $current()->join('work_authorizations as w', 'w.person_id', '=', 'e.person_id')->where('w.tenant_id', $tenantId)->where('w.status', 'active')->whereNotNull('w.expiry_date')
            ->where('w.expiry_date', '<', $today)->select(['w.id as id', 'e.id as employee_id', 'w.type', 'w.country', 'w.expiry_date']);
        foreach ($this->stream($q, 'w.id') as $row) {
            yield new Finding((int) $row->employee_id, 'wa:'.$row->id, 'Expired work authorization: '.str_replace('_', ' ', $row->type), 'Expired on '.substr($row->expiry_date, 0, 10).'. The employee may not be authorized to work.', 'critical',
                ['work_authorization', (int) $row->id], ['kind' => 'work_authorization', 'type' => $row->type, 'country' => $row->country, 'expired_on' => substr($row->expiry_date, 0, 10)], Classification::HIGHLY_SENSITIVE);
        }

        // Certifications.
        $q = $current()->join('employee_certifications as c', 'c.person_id', '=', 'e.person_id')->where('c.tenant_id', $tenantId)->whereNotNull('c.expiry_date')->where('c.expiry_date', '<', $today)
            ->select(['c.id as id', 'e.id as employee_id', 'c.name', 'c.expiry_date']);
        foreach ($this->stream($q, 'c.id') as $row) {
            yield new Finding((int) $row->employee_id, 'cert:'.$row->id, 'Expired certification: '.$row->name, 'Expired on '.substr($row->expiry_date, 0, 10).'.', 'info',
                ['certification', (int) $row->id], ['kind' => 'certification', 'expired_on' => substr($row->expiry_date, 0, 10)], Classification::EMPLOYEE);
        }
    }
}
