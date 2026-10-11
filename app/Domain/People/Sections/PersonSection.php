<?php

namespace App\Domain\People\Sections;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared behaviour of the personal profile sections that hang off the person (contacts, emergency contacts,
 * dependents, education, experience, certifications).
 *
 * Every mutation runs in a transaction that locks the employee row, writes an audit entry (field names, and
 * values only for non-sensitive sections) and records a domain event whose summary never carries sensitive values.
 * Authorization (view / edit group) is decided by the controller before a service is called.
 */
abstract class PersonSection
{
    /** Hard cap of rows per person and section: lists are returned unpaginated. */
    public const CAP = 200;

    public function __construct(protected DomainEvents $events) {}

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Human label used in summaries, e.g. "Emergency contact". */
    abstract protected function label(): string;

    /** Event type stem, e.g. "EmergencyContact" gives EmergencyContactAdded / Updated / Removed. */
    abstract protected function eventStem(): string;

    /** Classification of the events recorded for this section. */
    abstract protected function classification(): string;

    /** Sections holding sensitive values never put values in the audit trail. */
    protected function redactValues(): bool
    {
        return false;
    }

    /** Fields whose values never reach the audit trail. */
    protected function hiddenFields(): array
    {
        return [];
    }

    /** [[startField, endField, message]] pairs that must stay in order. */
    protected function datePairs(): array
    {
        return [];
    }

    /** Descriptor appended to the event summary for non-sensitive sections, e.g. "BSc, MIT". */
    protected function descriptor(Model $row): ?string
    {
        return null;
    }

    protected function summaryLabel(Model $row): string
    {
        return $this->label();
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    /** Hooks. */
    protected function creating(Employee $employee, array $data): array
    {
        return $data;
    }

    protected function updating(Employee $employee, Model $row, array $data): array
    {
        return $data;
    }

    protected function saved(Employee $employee, Model $row, bool $created): void {}

    protected function beforeRemove(Employee $employee, Model $row): void {}

    protected function removed(Employee $employee, Model $row): void {}

    protected function query(Employee $employee): Builder
    {
        return ($this->modelClass())::query()->where('person_id', $employee->person_id);
    }

    public function list(Employee $employee): Collection
    {
        return $this->orderBy($this->query($employee))->limit(self::CAP)->get();
    }

    public function find(Employee $employee, string $uuid): Model
    {
        // Malformed ids are simply "not found".
        abort_unless(Str::isUuid($uuid), 404);

        return $this->query($employee)->where('uuid', $uuid)->firstOrFail();
    }

    public function create(Employee $employee, array $data): Model
    {
        return DB::transaction(function () use ($employee, $data) {
            $this->lock($employee);
            if ($this->query($employee)->count() >= self::CAP) {
                throw ValidationException::withMessages(['record' => ['You have reached the limit of '.self::CAP.' records for this section.']]);
            }
            $data = $this->creating($employee, $this->withDocument($employee, $data));
            $this->assertDatePairs($data, null);
            $class = $this->modelClass();
            $row = new $class($data);
            $row->person_id = $employee->person_id;
            $row->save();
            $this->saved($employee, $row, true);
            $row = $row->refresh();

            $this->audit($employee, 'added', $row, ['changes' => array_keys($data)]);
            $this->event($employee, 'Added', $row);

            return $row;
        });
    }

    public function update(Employee $employee, string $uuid, array $data): Model
    {
        return DB::transaction(function () use ($employee, $uuid, $data) {
            $this->lock($employee);
            $row = $this->find($employee, $uuid);
            $data = $this->updating($employee, $row, $this->withDocument($employee, $data));
            $this->assertDatePairs($data, $row);
            $row->fill($data);
            $dirty = $row->getDirty();
            if ($dirty === []) {
                return $row;
            }
            $original = collect($dirty)->mapWithKeys(fn ($v, $k) => [$k => $row->getOriginal($k)])->all();
            $row->save();
            $this->saved($employee, $row, false);
            $row = $row->refresh();

            $this->audit($employee, 'updated', $row, ['changes' => $this->diff($original, $dirty)]);
            $this->event($employee, 'Updated', $row);

            return $row;
        });
    }

    public function delete(Employee $employee, string $uuid): void
    {
        DB::transaction(function () use ($employee, $uuid) {
            $this->lock($employee);
            $row = $this->find($employee, $uuid);
            $this->beforeRemove($employee, $row);
            $row->delete();
            $this->removed($employee, $row);

            $this->audit($employee, 'removed', $row);
            $this->event($employee, 'Removed', $row);
        });
    }

    /**
     * Verification (education, certifications). Editing verified facts later resets the status in updating().
     */
    public function verify(Employee $employee, string $uuid, string $status, ?string $note): Model
    {
        return DB::transaction(function () use ($employee, $uuid, $status, $note) {
            $this->lock($employee);
            $row = $this->find($employee, $uuid);
            $verified = $status !== 'unverified';
            $row->verification_status = $status;
            $row->verified_by = $verified ? auth()->id() : null;
            $row->verified_at = $verified ? now() : null;
            $row->verification_note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $row->save();

            $this->audit($employee, 'verification_'.$status, $row, ['status' => $status, 'note' => $row->verification_note]);
            $this->event($employee, 'Verified', $row, $this->summaryLabel($row).' marked '.$status.'.', ['status' => $status]);

            return $row->refresh();
        });
    }

    /**
     * Editing facts a reviewer verified voids the verification (status back to unverified).
     *
     * @param  array<int,string>  $facts
     */
    protected function resetVerification(Model $row, array $data, array $facts): array
    {
        if ($row->verification_status === 'unverified') {
            return $data;
        }
        $probe = clone $row;
        $probe->fill($data);
        if (array_intersect($facts, array_keys($probe->getDirty()))) {
            $data += ['verification_status' => 'unverified', 'verified_by' => null, 'verified_at' => null, 'verification_note' => null];
        }

        return $data;
    }

    protected function lock(Employee $employee): void
    {
        Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
    }

    // ---- primary handling -----------------------------------------------------------------------

    /**
     * Makes exactly one of the given current rows primary (the preferred one when given, else the existing
     * primary, else the oldest). Rows not in the list are never primary.
     *
     * @param  Collection<int,Model>  $current
     */
    protected function enforcePrimary(Collection $current, ?int $preferredId): void
    {
        if ($current->isEmpty()) {
            return;
        }
        $keep = $preferredId && $current->contains('id', $preferredId)
            ? $preferredId
            : ($current->firstWhere('is_primary', true)?->id ?? $current->first()->id);
        foreach ($current as $row) {
            $want = $row->id === $keep;
            if ((bool) $row->is_primary !== $want) {
                $row->is_primary = $want;
                $row->save();
            }
        }
    }

    // ---- documents ---------------------------------------------------------------------------------

    /** Replaces a document_uuid input with the document_id column after proving the document belongs to the person. */
    protected function withDocument(Employee $employee, array $data): array
    {
        if (! array_key_exists('document_uuid', $data)) {
            return $data;
        }
        $uuid = $data['document_uuid'];
        unset($data['document_uuid']);
        $data['document_id'] = $uuid === null ? null : $this->documentId($employee, $uuid);

        return $data;
    }

    protected function documentId(Employee $employee, string $uuid): int
    {
        $id = DB::table('employee_documents')->where('tenant_id', app(TenantContext::class)->id)->where('person_id', $employee->person_id)
            ->where('uuid', $uuid)->whereNull('deleted_at')->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['document_uuid' => ['That document was not found on this employee\'s profile.']]);
        }

        return (int) $id;
    }

    /** @param  array<int,int|null>  $ids @return array<int,string> document id => uuid */
    public function documentUuids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : DB::table('employee_documents')->where('tenant_id', app(TenantContext::class)->id)->whereIn('id', $ids)->pluck('uuid', 'id')->all();
    }

    // ---- validation helpers ----------------------------------------------------------------------

    private function assertDatePairs(array $incoming, ?Model $existing): void
    {
        foreach ($this->datePairs() as [$start, $end, $message]) {
            $from = array_key_exists($start, $incoming) ? $incoming[$start] : $existing?->{$start};
            $to = array_key_exists($end, $incoming) ? $incoming[$end] : $existing?->{$end};
            $from = $from instanceof \DateTimeInterface ? $from->format('Y-m-d') : $from;
            $to = $to instanceof \DateTimeInterface ? $to->format('Y-m-d') : $to;
            if ($from && $to && $to < $from) {
                throw ValidationException::withMessages([$end => [$message]]);
            }
        }
    }

    protected function today(): string
    {
        return TenantClock::today();
    }

    // ---- audit / events ----------------------------------------------------------------------------

    protected function diff(array $original, array $changes): array
    {
        $out = [];
        foreach ($changes as $field => $new) {
            $out[$field] = ($this->redactValues() || in_array($field, $this->hiddenFields(), true)) ? '[changed]' : ['from' => $original[$field] ?? null, 'to' => $new];
        }

        return $out;
    }

    protected function audit(Employee $employee, string $verb, Model $row, array $context = []): void
    {
        Audit::record(Str::snake($this->eventStem()).'_'.$verb, 'people', 'employee:'.$employee->uuid, ['record' => $row->uuid] + $context);
    }

    protected function event(Employee $employee, string $verb, Model $row, ?string $summary = null, array $payload = []): void
    {
        if ($summary === null) {
            $descriptor = $this->descriptor($row);
            $summary = $this->summaryLabel($row).' '.strtolower($verb).($descriptor ? ': '.$descriptor : '').'.';
        }
        $this->events->record($this->eventStem().$verb, [
            'employee' => $employee, 'summary' => $summary, 'classification' => $this->classification(),
            'payload' => ['record' => $row->uuid] + $payload, 'subject' => [Str::snake($this->eventStem()), $row->id],
        ]);
    }
}
