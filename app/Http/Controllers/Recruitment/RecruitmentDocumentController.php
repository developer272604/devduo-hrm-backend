<?php

namespace App\Http\Controllers\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Recruitment\Models\OfferDocument;
use App\Domain\Recruitment\Models\OfferTemplate;
use App\Domain\Recruitment\OfferDocumentService;
use App\Domain\Recruitment\OfferService;
use App\Domain\Recruitment\ResumeService;
use Illuminate\Http\Request;

final class RecruitmentDocumentController
{
    // ---- resumes ---------------------------------------------------------------------------------------------------------------------
    public function resumes(Request $r, string $uuid, ResumeService $svc)
    {
        return ['data' => $svc->list($r->user(), $uuid)];
    }

    public function uploadResume(Request $r, string $uuid, ResumeService $svc)
    {
        $r->validate(['file' => 'required|file']);

        return response()->json($svc->present($svc->upload($r->user(), $uuid, $r->file('file'))), 201);
    }

    public function downloadResume(Request $r, string $uuid, int $version, ResumeService $svc)
    {
        return $svc->download($r->user(), $uuid, $version);
    }

    public function removeResume(Request $r, string $uuid, int $version, ResumeService $svc)
    {
        $svc->remove($r->user(), $uuid, $version);

        return response()->noContent();
    }

    public function reviewResume(Request $r, string $uuid, int $version, ResumeService $svc)
    {
        $data = $r->validate(['status' => 'required|in:available,rejected']);

        return $svc->present($svc->review($r->user(), $uuid, $version, $data['status']));
    }

    // ---- offer templates -------------------------------------------------------------------------------------------------------------
    public function templates()
    {
        return ['merge_fields' => OfferDocumentService::FIELDS, 'data' => OfferTemplate::orderBy('code')->orderByDesc('version')->get()
            ->map(fn ($t) => ['uuid' => $t->uuid, 'code' => $t->code, 'version' => $t->version, 'name' => $t->name, 'status' => $t->status, 'body' => $t->body, 'created_at' => $t->created_at?->toIso8601String()])->all()];
    }

    public function createTemplate(Request $r, OfferDocumentService $svc)
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/'], 'name' => 'required|string|max:160', 'body' => 'required|string|max:50000']);

        return response()->json(['uuid' => $svc->createTemplate($r->user(), $d)->uuid], 201);
    }

    // ---- offer documents -------------------------------------------------------------------------------------------------------------
    private function offer(Request $r, string $uuid)
    {
        $u = $r->user();

        return app(OfferService::class)->find($u, $uuid, $u->allows('recruitment.offer.manage') ? 'recruitment.offer.manage' : 'recruitment.offer.approve');
    }

    public function offerDocuments(Request $r, string $uuid, OfferDocumentService $svc)
    {
        $offer = $this->offer($r, $uuid);

        return ['data' => OfferDocument::where('offer_id', $offer->id)->orderByDesc('version')->get()->map(fn ($d) => $svc->present($d))->all()];
    }

    public function generateOfferDocument(Request $r, string $uuid, OfferDocumentService $svc)
    {
        $d = $r->validate(['template_uuid' => 'nullable|uuid']);
        abort_unless($r->user()->allows('recruitment.offer.manage'), 403);
        $offer = $this->offer($r, $uuid);

        return response()->json($svc->present($svc->generate($r->user(), $offer, $d['template_uuid'] ?? null)), 201);
    }

    public function printOfferDocument(Request $r, string $uuid, int $version, OfferDocumentService $svc)
    {
        $offer = $this->offer($r, $uuid);
        $doc = OfferDocument::where('offer_id', $offer->id)->where('version', $version)->firstOrFail();
        $html = $svc->html($r->user(), $doc);
        Audit::record('offer_document.viewed', 'recruitment', $offer->uuid, ['version' => $doc->version]);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"]);
    }
}
