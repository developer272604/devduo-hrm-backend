<?php

namespace App\Domain\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Recruitment\Models\Application;
use App\Domain\Recruitment\Models\Candidate;
use App\Domain\Recruitment\Models\Offer;
use App\Domain\Recruitment\Models\OfferDocument;
use App\Domain\Recruitment\Models\OfferTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tenant offer-letter templates (versioned, restricted HTML + merge fields) and immutable per-offer snapshots.
 * Rendering is plain token substitution of HTML-escaped values: no expressions, no code. Regeneration appends a version.
 */
final class OfferDocumentService
{
    public const FIELDS = ['candidate_name', 'job_title', 'start_date', 'valid_until', 'company_name', 'offer_version', 'currency', 'compensation_reference'];

    private const TAGS = 'p|br|strong|em|b|i|u|ul|ol|li|h1|h2|h3';

    private const COMP_MARK = "\u{E000}COMPENSATION\u{E000}";

    public const COMP_PLACEHOLDER = '[Compensation details are shared separately]';

    /** Allow-listed tags without attributes; everything else becomes escaped text. */
    public static function sanitize(string $html): string
    {
        $html = preg_replace('~<(/?)('.self::TAGS.')(?:\s[^<>]*)?/?>~i', '<$1$2>', $html) ?? '';
        $parts = preg_split('~(</?(?:'.self::TAGS.')>)~i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 1 ? strtolower($part) : htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        }

        return $out;
    }

    public function createTemplate(User $actor, array $d): OfferTemplate
    {
        abort_unless($actor->allows('recruitment.offer.manage'), 403);
        $body = str_replace(self::COMP_MARK, '', self::sanitize($d['body']));
        preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', $body, $m);
        $unknown = array_values(array_unique(array_diff($m[1], self::FIELDS)));
        if ($unknown) {
            throw ValidationException::withMessages(['body' => ['Unknown merge fields: '.implode(', ', array_map(fn ($f) => mb_substr($f, 0, 40), $unknown)).'. Allowed: '.implode(', ', self::FIELDS).'.']]);
        }

        return DB::transaction(function () use ($actor, $d, $body) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->first();
            $version = (OfferTemplate::where('code', $d['code'])->max('version') ?? 0) + 1;
            OfferTemplate::where('code', $d['code'])->where('status', 'active')->update(['status' => 'retired']);
            $t = OfferTemplate::create(['code' => $d['code'], 'version' => $version, 'name' => $d['name'], 'body' => $body, 'status' => 'active', 'created_by' => $actor->id]);
            Audit::record('offer_template.created', 'recruitment', $t->uuid, ['code' => $t->code, 'version' => $version]);

            return $t;
        });
    }

    /** Called from the offer workflow: a document is made only when the workspace has an active template. */
    public function autoGenerate(User $actor, Offer $offer, string $trigger): ?OfferDocument
    {
        if (! OfferTemplate::where('status', 'active')->exists()) {
            return null;
        }
        if ($trigger === 'sent' && OfferDocument::where('offer_id', $offer->id)->exists()) {
            return null;
        }

        return $this->generate($actor, $offer, null, $trigger);
    }

    public function generate(User $actor, Offer $offer, ?string $templateUuid, string $trigger = 'regenerated'): OfferDocument
    {
        abort_unless(in_array($offer->status, ['approved', 'sent'], true), 409, 'Documents are generated only for approved or sent offers.');
        $template = $templateUuid ? OfferTemplate::where('uuid', $templateUuid)->where('status', 'active')->first() : OfferTemplate::where('status', 'active')->orderByDesc('id')->first();
        if (! $template) {
            throw ValidationException::withMessages(['template_uuid' => ['No active offer template is available.']]);
        }
        $offer->loadMissing('application.candidate');
        $c = $offer->application->candidate;
        abort_if($c->anonymized_at, 409, 'Applicant data was anonymized.');
        $values = ['candidate_name' => $c->name, 'job_title' => $offer->job_title, 'start_date' => $offer->start_date?->toDateString(), 'valid_until' => $offer->expires_on?->toDateString() ?? 'Not specified',
            'company_name' => (string) DB::table('tenants')->where('id', $offer->tenant_id)->value('name'), 'offer_version' => (string) $offer->version, 'currency' => $offer->currency ? strtoupper($offer->currency) : ''];
        $content = $this->merge($template->body, $values);

        return DB::transaction(function () use ($actor, $offer, $template, $content, $trigger) {
            $locked = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['approved', 'sent'], true), 409, 'Documents are generated only for approved or sent offers.');
            $doc = OfferDocument::create(['offer_id' => $locked->id, 'version' => (OfferDocument::where('offer_id', $locked->id)->max('version') ?? 0) + 1, 'template_id' => $template->id,
                'template_version' => $template->version, 'trigger' => $trigger, 'content' => $content, 'compensation_snapshot' => $locked->compensation_reference, 'sha256' => hash('sha256', $content), 'generated_by' => $actor->id]);
            Audit::record('offer_document.generated', 'recruitment', $locked->uuid, ['document' => $doc->uuid, 'version' => $doc->version, 'template_version' => $template->version, 'trigger' => $trigger]);

            return $doc;
        });
    }

    private function merge(string $body, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($values) {
            if ($m[1] === 'compensation_reference') {
                return self::COMP_MARK;
            }

            return htmlspecialchars((string) ($values[$m[1]] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $body) ?? '';
    }

    /** Final HTML for one viewer: compensation text appears only for payroll.compensation.view holders. */
    public function html(User $viewer, OfferDocument $doc): string
    {
        abort_if($doc->purged_at || $doc->content === null, 404, 'This document is no longer available.');
        $comp = $viewer->allows('payroll.compensation.view') && $doc->compensation_snapshot ? htmlspecialchars((string) $doc->compensation_snapshot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : self::COMP_PLACEHOLDER;
        $body = str_replace(self::COMP_MARK, $comp, $doc->content);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Offer letter</title><style>body{font-family:Arial,sans-serif;max-width:720px;margin:2rem auto;line-height:1.5}@media print{body{margin:0}}</style></head><body>'.$body.'</body></html>';
    }

    public function present(OfferDocument $d): array
    {
        return ['uuid' => $d->uuid, 'version' => $d->version, 'template_version' => $d->template_version, 'trigger' => $d->trigger, 'sha256' => $d->sha256, 'generated_at' => $d->created_at?->toIso8601String(), 'available' => ! $d->purged_at];
    }

    public function purgeForCandidate(Candidate $candidate): int
    {
        $n = 0;
        $offers = Offer::whereIn('application_id', Application::where('candidate_id', $candidate->id)->select('id'))->select('id');
        \App\Domain\Recruitment\Models\OfferRelease::whereIn('offer_id', clone $offers)->update(['content' => null]);
        OfferDocument::whereNull('purged_at')->whereIn('offer_id', $offers)->each(function ($d) use (&$n) {
            $d->update(['content' => null, 'compensation_snapshot' => null, 'purged_at' => now()]);
            $n++;
        });

        return $n;
    }
}
