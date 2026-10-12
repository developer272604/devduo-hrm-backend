<?php

namespace App\Domain\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\People\Records\FileInspector;
use App\Domain\Recruitment\Models\Application;
use App\Domain\Recruitment\Models\Candidate;
use App\Domain\Recruitment\Models\Resume;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private, versioned resume files. Content is never parsed; files are served only through authorized streaming. */
final class ResumeService
{
    public const DISK = 'hrm_private';

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_VERSIONS = 20;

    private const EXTENSIONS = ['pdf', 'doc', 'docx'];

    public function candidate(User $actor, string $uuid, string $permission): Candidate
    {
        abort_unless($actor->allows($permission), 403);

        return app(HiringScope::class)->candidates($actor, $permission)->where('uuid', $uuid)->firstOrFail();
    }

    public function upload(User $actor, string $candidateUuid, UploadedFile $file): Resume
    {
        $candidate = $this->candidate($actor, $candidateUuid, 'recruitment.manage');

        return $this->store($candidate, $file, $actor);
    }

    public function uploadFromPortal(Candidate $candidate, UploadedFile $file): Resume
    {
        abort_if($candidate->anonymized_at || $candidate->retention_until < now('UTC')->toDateString(), 409, 'Applicant data is no longer available.');

        return $this->store($candidate, $file, null);
    }

    private function store(Candidate $candidate, UploadedFile $file, ?User $actor): Resume
    {
        abort_if($candidate->anonymized_at, 409, 'Applicant data was anonymized; files cannot be added.');
        if ($file->isValid() && (int) $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => ['The resume is larger than 5 MB.']]);
        }
        $facts = app(FileInspector::class)->inspectDocument($file);
        if (! in_array($facts['extension'], self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => ['Upload a PDF, DOC or DOCX resume.']]);
        }
        $name = Str::lower(Str::random(40)).'.'.$facts['extension']; // never derived from the client's name
        $stored = Storage::disk(self::DISK)->putFileAs('recruitment/'.$candidate->tenant_id.'/'.$candidate->uuid, $file, $name, ['visibility' => 'private']);
        abort_unless($stored, 500, 'The file could not be stored.');
        try {
            return DB::transaction(function () use ($actor, $candidate, $facts, $stored) {
                $locked = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                abort_if($locked->anonymized_at, 409, 'Applicant data was anonymized; files cannot be added.');
                abort_if(Resume::where('candidate_id', $locked->id)->count() >= self::MAX_VERSIONS, 409, 'The maximum number of resume versions was reached.');
                $resume = Resume::create(['candidate_id' => $locked->id, 'version' => (Resume::where('candidate_id', $locked->id)->max('version') ?? 0) + 1, 'disk' => self::DISK, 'path' => $stored,
                    'original_name' => $facts['name'], 'mime' => $facts['mime'], 'size' => $facts['size'], 'sha256' => $facts['sha256'], 'uploaded_by' => $actor?->id,
                    'uploaded_source' => $actor ? 'recruiter' : 'candidate_portal', 'review_status' => $actor ? 'available' : 'pending_review']);
                if ($actor) {
                    Audit::record('resume.uploaded', 'recruitment', $locked->uuid, ['version' => $resume->version, 'size' => $facts['size'], 'mime' => $facts['mime']]);
                } else {
                    \App\Domain\Audit\AuditChain::append(['user_id' => null, 'actor' => 'Candidate', 'action' => 'resume.candidate_uploaded', 'module' => 'recruitment', 'resource' => $locked->uuid, 'ip_address' => request()->ip(), 'context' => ['version' => $resume->version, 'size' => $facts['size'], 'mime' => $facts['mime']]]);
                }

                return $resume;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($stored);
            throw $e;
        }
    }

    public function list(User $actor, string $candidateUuid): array
    {
        $candidate = $this->candidate($actor, $candidateUuid, 'recruitment.view');

        return Resume::where('candidate_id', $candidate->id)->orderByDesc('version')->get()->map(fn ($r) => $this->present($r))->all();
    }

    public function download(User $actor, string $candidateUuid, int $version): StreamedResponse
    {
        $candidate = $this->candidate($actor, $candidateUuid, 'recruitment.view');
        $resume = Resume::where('candidate_id', $candidate->id)->where('version', $version)->firstOrFail();
        abort_if($resume->purged_at || ! $resume->path, 404, 'This file is no longer available.');
        $disk = Storage::disk($resume->disk);
        abort_unless($disk->exists($resume->path), 404, 'This file is no longer available.');
        Audit::record('resume.downloaded', 'recruitment', $candidate->uuid, ['version' => $resume->version]);

        return $disk->response($resume->path, FileInspector::displayName($resume->original_name), ['Content-Type' => $resume->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0'], 'attachment');
    }

    public function remove(User $actor, string $candidateUuid, int $version): void
    {
        $candidate = $this->candidate($actor, $candidateUuid, 'recruitment.manage');
        $resume = Resume::where('candidate_id', $candidate->id)->where('version', $version)->firstOrFail();
        abort_if($resume->purged_at, 409, 'This file was already removed.');
        $this->purge($resume);
        Audit::record('resume.removed', 'recruitment', $candidate->uuid, ['version' => $version]);
    }

    public function review(User $actor, string $candidateUuid, int $version, string $status): Resume
    {
        $candidate = $this->candidate($actor, $candidateUuid, 'recruitment.manage');
        $resume = Resume::where('candidate_id', $candidate->id)->where('version', $version)->firstOrFail();
        abort_if($resume->purged_at, 404, 'This file is no longer available.');
        abort_unless(in_array($status, ['available', 'rejected'], true), 422, 'Choose a valid review status.');
        $resume->update(['review_status' => $status, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        Audit::record('resume.reviewed', 'recruitment', $candidate->uuid, ['version' => $version, 'status' => $status]);

        return $resume->refresh();
    }

    /** Deletes the stored file and blanks identifying metadata. Idempotent. */
    public function purge(Resume $resume): bool
    {
        if ($resume->purged_at) {
            return false;
        }
        if ($resume->path) {
            Storage::disk($resume->disk)->delete($resume->path);
        }
        $resume->update(['path' => null, 'original_name' => 'removed', 'purged_at' => now()]);

        return true;
    }

    public function purgeForCandidate(Candidate $candidate): int
    {
        $n = 0;
        Resume::where('candidate_id', $candidate->id)->whereNull('purged_at')->each(function ($r) use (&$n) {
            $n += $this->purge($r) ? 1 : 0;
        });

        return $n;
    }

    public function present(Resume $r): array
    {
        return ['version' => $r->version, 'original_name' => $r->purged_at ? null : FileInspector::displayName($r->original_name), 'mime' => $r->mime, 'size' => (int) $r->size, 'sha256' => $r->sha256,
            'uploaded_at' => $r->created_at?->toIso8601String(), 'available' => ! $r->purged_at, 'source' => $r->uploaded_source ?? 'recruiter', 'review_status' => $r->review_status ?? 'available'];
    }

    /** Anonymized candidates always; expired ones unless hired (hired applicants are retained through the employee record, like anonymization). */
    public static function retentionPurgeable(Candidate $c): bool
    {
        return $c->anonymized_at !== null || ($c->retention_until < now('UTC')->toDateString() && ! Application::where('candidate_id', $c->id)->whereNotNull('employee_id')->exists());
    }
}
