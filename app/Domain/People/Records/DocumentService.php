<?php

namespace App\Domain\People\Records;

use App\Domain\People\Models\DocumentType;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeDocument;
use App\Domain\People\Models\EmployeeDocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private document storage with append-only versions.
 *
 * Files live on the private disk under {tenant_id}/{person uuid}/{random}.{ext}; the client's file name is kept only as
 * display metadata. Nothing here ever produces a URL: downloads are authorized, streamed and (for confidential types) audited.
 */
final class DocumentService
{
    public const DISK = 'hrm_private';

    /** Types the browser may render inline (previews). */
    private const PREVIEWABLE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private FileInspector $inspector, private RecordAccess $access) {}

    /** 'all' (HR), 'own' (the employee: only employee-visible documents) or null (no access). */
    public function visibility(User $user, Employee $employee): ?string
    {
        if ($this->access->reaches($user, $employee, 'people.documents.manage') || $this->access->reaches($user, $employee, 'people.confidential.view')) {
            return 'all';
        }

        return $this->access->isSelf($user, $employee) ? 'own' : null;
    }

    public function query(Employee $employee, string $mode): Builder
    {
        return EmployeeDocument::query()->where('person_id', $employee->person_id)->when($mode === 'own', fn ($q) => $q->where('employee_visible', true));
    }

    public function find(Employee $employee, string $mode, string $uuid): EmployeeDocument
    {
        return $this->query($employee, $mode)->where('uuid', $uuid)->with(['type', 'currentVersion'])->firstOrFail();
    }

    public function upload(User $actor, Employee $employee, UploadedFile $file, array $meta, bool $asEmployee): EmployeeDocument
    {
        $facts = $this->inspector->inspectDocument($file);
        app(DocumentTypes::class)->ensureDefaults();
        $type = DocumentType::query()->where('code', $meta['document_type'])->where('active', true)->first();
        if (! $type) {
            throw ValidationException::withMessages(['document_type' => 'Choose a valid, active document type.']);
        }
        $this->assertDates($type, $meta['issue_date'] ?? null, $meta['expiry_date'] ?? null);
        $stored = $this->store($employee, $file, $facts);

        try {
            return DB::transaction(function () use ($actor, $employee, $meta, $asEmployee, $type, $facts, $stored) {
                $document = EmployeeDocument::query()->create([
                    'person_id' => $employee->person_id, 'employee_id' => $employee->id, 'document_type_id' => $type->id,
                    'title' => trim((string) ($meta['title'] ?? '')) ?: $type->name, 'issue_date' => $meta['issue_date'] ?? null, 'expiry_date' => $meta['expiry_date'] ?? null,
                    'reference_number' => $meta['reference_number'] ?? null, 'verification_status' => 'pending', 'employee_visible' => $asEmployee ? true : (bool) ($meta['employee_visible'] ?? true),
                    'notes' => $meta['notes'] ?? null, 'tags' => $this->tags($meta['tags'] ?? null),
                ]);
                $version = $this->appendVersion($document, $actor, $stored, $facts, 1);
                $document->update(['current_version_id' => $version->id]);
                RecordLog::audit('uploaded', $employee, 'document', ['record' => $document->uuid, 'type' => $type->code, 'version' => 1, 'size' => $facts['size'], 'mime' => $facts['mime']]);
                RecordLog::event('DocumentUploaded', $employee, 'Document uploaded ('.$type->name.').', $this->eventClass($type), ['employee_document', $document->id],
                    ['document' => $document->uuid, 'type' => $type->code, 'category' => $type->category, 'version' => 1]);

                return $document->load(['type', 'currentVersion']);
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($stored);
            throw $e;
        }
    }

    public function addVersion(User $actor, Employee $employee, EmployeeDocument $document, UploadedFile $file, bool $asEmployee): EmployeeDocument
    {
        $facts = $this->inspector->inspectDocument($file);
        $stored = $this->store($employee, $file, $facts);

        try {
            return DB::transaction(function () use ($actor, $employee, $document, $facts, $stored) {
                $locked = EmployeeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                $next = (int) EmployeeDocumentVersion::query()->where('document_id', $locked->id)->max('version') + 1;
                $version = $this->appendVersion($locked, $actor, $stored, $facts, $next);
                // A new file has not been checked yet.
                $locked->update(['current_version_id' => $version->id, 'verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null]);
                $type = $locked->type ?? DocumentType::query()->find($locked->document_type_id);
                RecordLog::audit('version_added', $employee, 'document', ['record' => $locked->uuid, 'version' => $next, 'size' => $facts['size'], 'mime' => $facts['mime']]);
                RecordLog::event('DocumentUploaded', $employee, 'New version uploaded ('.($type?->name ?? 'document').').', $this->eventClass($type), ['employee_document', $locked->id],
                    ['document' => $locked->uuid, 'type' => $type?->code, 'version' => $next]);

                return $locked->load(['type', 'currentVersion']);
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($stored);
            throw $e;
        }
    }

    public function update(User $actor, Employee $employee, EmployeeDocument $document, array $data, bool $asEmployee): EmployeeDocument
    {
        $type = $document->type ?? DocumentType::query()->find($document->document_type_id);
        $issue = array_key_exists('issue_date', $data) ? $data['issue_date'] : $document->issue_date?->toDateString();
        $expiry = array_key_exists('expiry_date', $data) ? $data['expiry_date'] : $document->expiry_date?->toDateString();
        $this->assertDates($type, $issue, $expiry);
        $fields = array_intersect_key($data, array_flip(['title', 'issue_date', 'expiry_date', 'reference_number', 'notes']));
        if (array_key_exists('tags', $data)) {
            $fields['tags'] = $this->tags($data['tags']);
        }
        if (! $asEmployee && array_key_exists('employee_visible', $data)) {
            $fields['employee_visible'] = (bool) $data['employee_visible'];
        }
        if ($asEmployee) {
            $fields['verification_status'] = 'pending'; // an employee edit must be re-checked by HR
            $fields['verified_by'] = null;
            $fields['verified_at'] = null;
        }

        return DB::transaction(function () use ($employee, $document, $fields, $type) {
            $document->fill($fields)->save();
            RecordLog::audit('updated', $employee, 'document', ['record' => $document->uuid, 'fields' => array_keys($fields)]);
            RecordLog::event('DocumentUpdated', $employee, 'Document details updated ('.($type?->name ?? 'document').').', $this->eventClass($type), ['employee_document', $document->id], ['document' => $document->uuid]);

            return $document->refresh()->load(['type', 'currentVersion']);
        });
    }

    public function verify(User $actor, Employee $employee, EmployeeDocument $document, string $status): EmployeeDocument
    {
        return DB::transaction(function () use ($actor, $employee, $document, $status) {
            $pending = $status === 'pending';
            $document->update(['verification_status' => $status, 'verified_by' => $pending ? null : $actor->id, 'verified_at' => $pending ? null : now()]);
            $type = $document->type ?? DocumentType::query()->find($document->document_type_id);
            RecordLog::audit($status === 'pending' ? 'verification_reset' : $status, $employee, 'document', ['record' => $document->uuid, 'type' => $type?->code]);
            if (! $pending) {
                RecordLog::event($status === 'verified' ? 'DocumentVerified' : 'DocumentRejected', $employee, 'Document '.$status.' ('.($type?->name ?? 'document').').', $this->eventClass($type),
                    ['employee_document', $document->id], ['document' => $document->uuid, 'type' => $type?->code, 'status' => $status]);
            }

            return $document->refresh()->load(['type', 'currentVersion']);
        });
    }

    /** Soft delete: the row and every stored version stay for retention and audit purposes. */
    public function delete(User $actor, Employee $employee, EmployeeDocument $document): void
    {
        DB::transaction(function () use ($employee, $document) {
            $type = $document->type ?? DocumentType::query()->find($document->document_type_id);
            $document->delete();
            RecordLog::audit('deleted', $employee, 'document', ['record' => $document->uuid, 'type' => $type?->code]);
            RecordLog::event('DocumentDeleted', $employee, 'Document removed ('.($type?->name ?? 'document').').', $this->eventClass($type), ['employee_document', $document->id], ['document' => $document->uuid]);
        });
    }

    /** Authorized streaming download; confidential documents are audited on every access. */
    public function download(User $actor, Employee $employee, EmployeeDocument $document, ?int $versionNumber, bool $inline): StreamedResponse
    {
        $version = $versionNumber
            ? EmployeeDocumentVersion::query()->where('document_id', $document->id)->where('version', $versionNumber)->firstOrFail()
            : EmployeeDocumentVersion::query()->findOrFail($document->current_version_id);
        $disk = Storage::disk($version->disk);
        abort_unless($disk->exists($version->path), 404, 'This file is no longer available.');
        $type = $document->type ?? DocumentType::query()->find($document->document_type_id);
        if (in_array($type?->classification ?? 'hr_confidential', ['hr_confidential', 'highly_sensitive'], true)) {
            RecordLog::audit('downloaded', $employee, 'document', ['record' => $document->uuid, 'version' => $version->version, 'type' => $type?->code, 'classification' => $type?->classification]);
        }

        return $disk->response($version->path, FileInspector::displayName($version->original_name), [
            'Content-Type' => $version->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0',
        ], $inline && in_array($version->mime, self::PREVIEWABLE, true) ? 'inline' : 'attachment');
    }

    /** @param array{edit?:bool,verify?:bool,delete?:bool} $can */
    public function present(EmployeeDocument $document, array $can = [], bool $withVersions = false): array
    {
        $type = $document->type;
        $current = $document->currentVersion;
        $out = [
            'uuid' => $document->uuid,
            'type' => $type ? ['code' => $type->code, 'name' => $type->name, 'category' => $type->category, 'classification' => $type->classification] : null,
            'title' => $document->title, 'issue_date' => $document->issue_date?->toDateString(), 'expiry_date' => $document->expiry_date?->toDateString(),
            'expiry_status' => Expiry::status($document->expiry_date), 'reference_number' => $document->reference_number, 'verification_status' => $document->verification_status,
            'verified_at' => $document->verified_at?->toIso8601String(), 'verified_by' => $document->verified_by ? User::query()->whereKey($document->verified_by)->value('name') : null,
            'employee_visible' => $document->employee_visible, 'notes' => $document->notes, 'tags' => $document->tags ?: [],
            'current_version' => $current ? $this->presentVersion($current) : null,
            'previewable' => $current ? in_array($current->mime, self::PREVIEWABLE, true) : false,
            'can' => ['download' => true, 'edit' => (bool) ($can['edit'] ?? false), 'verify' => (bool) ($can['verify'] ?? false), 'delete' => (bool) ($can['delete'] ?? false)],
            'created_at' => $document->created_at?->toIso8601String(), 'updated_at' => $document->updated_at?->toIso8601String(),
        ];
        if ($withVersions) {
            $out['versions'] = EmployeeDocumentVersion::query()->where('document_id', $document->id)->orderByDesc('version')->get()->map(fn ($v) => $this->presentVersion($v))->all();
        }

        return $out;
    }

    public function presentVersion(EmployeeDocumentVersion $version): array
    {
        return ['version' => $version->version, 'original_name' => FileInspector::displayName($version->original_name), 'mime' => $version->mime, 'size' => (int) $version->size, 'sha256' => $version->sha256,
            'uploaded_at' => $version->created_at?->toIso8601String(), 'uploaded_by' => $version->uploaded_by ? User::query()->whereKey($version->uploaded_by)->value('name') : null];
    }

    private function store(Employee $employee, UploadedFile $file, array $facts): string
    {
        $person = $employee->person ?? \App\Domain\People\Models\Person::query()->findOrFail($employee->person_id);
        $directory = $employee->tenant_id.'/'.$person->uuid;
        $name = Str::lower(Str::random(40)).'.'.$facts['extension']; // never derived from the client's file name
        $path = Storage::disk(self::DISK)->putFileAs($directory, $file, $name, ['visibility' => 'private']);
        abort_unless($path, 500, 'The file could not be stored.');

        return $path;
    }

    private function appendVersion(EmployeeDocument $document, User $actor, string $path, array $facts, int $number): EmployeeDocumentVersion
    {
        return EmployeeDocumentVersion::query()->create([
            'document_id' => $document->id, 'version' => $number, 'disk' => self::DISK, 'path' => $path, 'original_name' => $facts['name'], 'mime' => $facts['mime'], 'size' => $facts['size'],
            'sha256' => $facts['sha256'], 'uploaded_by' => $actor->id,
        ]);
    }

    private function assertDates(?DocumentType $type, ?string $issue, ?string $expiry): void
    {
        if ($type?->requires_expiry && ! $expiry) {
            throw ValidationException::withMessages(['expiry_date' => 'This type of document needs an expiry date.']);
        }
        if ($issue && $expiry && $expiry < $issue) {
            throw ValidationException::withMessages(['expiry_date' => 'The expiry date cannot be before the issue date.']);
        }
    }

    private function tags(?array $tags): ?array
    {
        if ($tags === null) {
            return null;
        }
        $clean = collect($tags)->filter(fn ($t) => is_string($t) && trim($t) !== '')->map(fn ($t) => mb_substr(trim($t), 0, 40))->unique()->take(20)->values()->all();

        return $clean ?: null;
    }

    private function eventClass(?DocumentType $type): string
    {
        return 'hr_confidential';
    }
}
