<?php

namespace App\Http\Controllers\Experience;

use App\Domain\Experience\AnnouncementService;
use App\Domain\Experience\Models\Announcement;
use App\Domain\Experience\Models\AnnouncementAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AnnouncementController
{
    public function __construct(private AnnouncementService $announcements) {}

    public function index(Request $r)
    {
        return $this->announcements->visible($r->user())->orderByDesc('pinned')->orderByDesc('publish_at')->orderByDesc('id')
            ->paginate(min(50, max(1, (int) $r->query('per_page', 20))))
            ->through(fn ($announcement) => $this->announcements->present($announcement, $r->user()));
    }

    public function create(Request $r)
    {
        $data = $r->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:50000',
            'communication_type' => 'sometimes|in:announcement,event,onboarding',
            'acknowledgment_required' => 'sometimes|boolean', 'pinned' => 'sometimes|boolean', 'publish_at' => 'nullable|date_format:Y-m-d', 'expires_at' => 'nullable|date_format:Y-m-d',
            'event_starts_at' => 'required_if:communication_type,event|nullable|date', 'event_ends_at' => 'nullable|date|after_or_equal:event_starts_at', 'event_location' => 'nullable|string|max:200',
            'audience' => 'sometimes|array:legal_entity_ids,location_ids,designation_ids,new_hires_within_days', 'audience.new_hires_within_days' => 'required_if:communication_type,onboarding|nullable|integer|min:1|max:90', 'audience.legal_entity_ids' => 'sometimes|array|max:50', 'audience.legal_entity_ids.*' => 'required|integer|min:1|distinct',
            'audience.location_ids' => 'sometimes|array|max:50', 'audience.location_ids.*' => 'required|integer|min:1|distinct',
            'audience.designation_ids' => 'sometimes|array|max:50', 'audience.designation_ids.*' => 'required|integer|min:1|distinct']);
        return response()->json($this->announcements->present($this->announcements->create($r->user(), $data), $r->user()), 201);
    }

    public function audienceOptions() { return $this->announcements->audienceOptions(); }

    public function publish(Request $r, string $uuid)
    {
        $announcement = Announcement::where('uuid', $uuid)->firstOrFail();
        return $this->announcements->present($this->announcements->publish($announcement, $r->user()), $r->user());
    }

    public function read(Request $r, string $uuid)
    {
        $announcement = $this->announcements->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        $this->announcements->record($r->user(), $announcement);
        return $this->announcements->present($announcement, $r->user());
    }

    public function acknowledge(Request $r, string $uuid)
    {
        $announcement = $this->announcements->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        abort_unless($announcement->status === 'published', 404);
        $this->announcements->record($r->user(), $announcement, true);
        return $this->announcements->present($announcement, $r->user());
    }

    public function rsvp(Request $r, string $uuid)
    {
        $data = $r->validate(['response' => 'required|in:attending,maybe,declined']);
        $announcement = $this->announcements->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        return response()->json($this->announcements->rsvp($r->user(), $announcement, $data['response']));
    }

    public function metrics(Request $r, string $uuid)
    {
        return $this->announcements->metrics(Announcement::where('uuid', $uuid)->firstOrFail());
    }

    public function addAttachment(Request $r, string $uuid)
    {
        $data = $r->validate(['file' => 'required|file|mimes:pdf,png,jpg,jpeg,doc,docx,xlsx|max:5120']);
        $announcement = Announcement::where('uuid', $uuid)->firstOrFail();
        abort_unless($announcement->status === 'draft', 409, 'Attachments can only be added to draft communications.');
        if ($announcement->attachments()->count() >= 5) throw ValidationException::withMessages(['attachment' => 'A communication can have at most five attachments.']);
        $file = $data['file'];
        $id = (string) Str::uuid();
        $path = 'tenant/'.$r->user()->tenant_id.'/announcements/'.$announcement->uuid.'/'.$id.'.'.$file->extension();
        Storage::disk('experience_private')->put($path, file_get_contents($file->getRealPath()));
        try {
            $attachment = AnnouncementAttachment::create(['uuid' => $id, 'announcement_id' => $announcement->id, 'uploaded_by' => $r->user()->id,
                'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(), 'path' => $path]);
        } catch (\Throwable $e) {
            Storage::disk('experience_private')->delete($path);
            throw $e;
        }
        \App\Domain\Audit\Audit::record('announcement.attachment_added', 'experience', 'announcement:'.$announcement->uuid);
        return response()->json(['uuid' => $attachment->uuid, 'filename' => $attachment->filename, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes], 201);
    }

    public function attachment(Request $r, string $uuid, string $attachment)
    {
        $announcement = $this->announcements->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        abort_unless($announcement->status === 'published', 404);
        $file = $announcement->attachments()->where('uuid', $attachment)->firstOrFail();
        abort_unless(Storage::disk('experience_private')->exists($file->path), 404);
        \App\Domain\Audit\Audit::record('announcement.attachment_accessed', 'experience', 'announcement:'.$announcement->uuid);
        return Storage::disk('experience_private')->download($file->path, $file->filename, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
