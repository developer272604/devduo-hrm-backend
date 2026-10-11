<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\Access\FieldAccess;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Shared authorization and presentation helpers of the profile-section controllers.
 *
 * Reads: the subject must be reachable (PeopleController::employee: 404 otherwise) and the viewer must be allowed
 * to see the section (FieldAccess::view on "<entity>.records": 403 with a friendly message otherwise).
 * Writes: FieldAccess::editGroup decides direct (apply) | request (409 change_request_required) | none (403).
 */
trait SectionAuthorization
{
    protected function assertView(Request $r, Employee $employee, string $field): void
    {
        abort_if(
            $this->access()->view($r->user(), $field, $employee) === FieldAccess::HIDDEN,
            403,
            'You do not have access to this section of the profile.'
        );
    }

    /** Returns 'direct' when the change may be applied; aborts otherwise. */
    protected function authorizeWrite(Request $r, Employee $employee, string $group): string
    {
        $mode = $this->access()->editGroup($r->user(), $group, $employee);
        if ($mode === 'none') {
            abort(403, 'You cannot make changes to this section of the profile.');
        }
        if ($mode === 'request') {
            throw new HttpResponseException(response()->json([
                'message' => 'These changes need approval before they apply.', 'code' => 'change_request_required', 'section' => $group,
            ], 409));
        }

        return $mode;
    }

    protected function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($value ?: null);
    }

    protected function instant(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->toIso8601String() : null;
    }

    /** Whether document references (uuids) may be shown to the viewer: the person themself or someone who can see documents. */
    protected function canSeeDocuments(Request $r, Employee $employee): bool
    {
        $relation = $this->access()->relation($r->user(), $employee);

        return $relation['self'] || $this->access()->view($r->user(), 'document.records', $employee, $relation) !== FieldAccess::HIDDEN;
    }

    /** @return array<int,string> user id => name for the verifier ids of the given rows */
    protected function verifierNames(Collection $rows): array
    {
        $ids = $rows->pluck('verified_by')->filter()->unique()->values()->all();

        return $ids === [] ? [] : User::query()->where('tenant_id', app(TenantContext::class)->id)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** @param  array<int,string>  $verifiers */
    protected function verification(Model $row, array $verifiers): array
    {
        return [
            'status' => $row->verification_status, 'verified_by' => $row->verified_by ? ($verifiers[$row->verified_by] ?? null) : null,
            'verified_at' => $this->instant($row->verified_at), 'note' => $row->verification_note ?? null,
        ];
    }

    /** @return array{status:string,note:?string} */
    protected function validateVerification(Request $r): array
    {
        return $r->validate(
            ['status' => 'required|in:verified,rejected,unverified', 'note' => 'nullable|string|max:500'],
            ['status.required' => 'Choose a verification status.', 'status.in' => 'The verification status must be verified, rejected or unverified.']
        ) + ['note' => null];
    }
}
