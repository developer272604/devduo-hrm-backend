<?php

namespace App\Http\Controllers\Recruitment;

use App\Domain\Audit\AuditChain;
use App\Domain\Recruitment\CandidateService;
use App\Domain\Recruitment\Models\Application;
use App\Domain\Recruitment\Models\ApplicationAction;
use App\Domain\Recruitment\Models\Candidate;
use App\Domain\Recruitment\Models\Interview;
use App\Domain\Recruitment\Models\Offer;
use App\Domain\Recruitment\Models\PortalChallenge;
use App\Domain\Recruitment\OfferService;
use App\Domain\Recruitment\RecruitmentNotify;
use App\Domain\Recruitment\ResumeService;
use App\Domain\Tenancy\TenantRunner;
use App\Domain\Work\TaskService;
use App\Jobs\SendCandidatePortalCode;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CandidatePortalController
{
    private function workspace(string $workspace, callable $action): mixed
    {
        abort_unless(config('recruitment.candidate_portal_enabled'), 503, 'Candidate portal access is not enabled.');
        $tenant = Tenant::where('slug', $workspace)->firstOrFail();
        abort_unless(in_array('recruitment', $tenant->settings['modules'] ?? [], true), 404);

        return app(TenantRunner::class)->run($tenant->id, fn () => $action($tenant));
    }

    private function candidates()
    {
        return Candidate::whereNull('anonymized_at')->where('retention_until', '>=', now('UTC')->toDateString());
    }

    private function record(string $action, Candidate $candidate): void
    {
        AuditChain::append(['user_id' => null, 'actor' => 'Candidate', 'action' => $action, 'module' => 'recruitment', 'resource' => $candidate->uuid, 'ip_address' => request()->ip(), 'context' => []]);
    }

    public function requestCode(Request $r, string $workspace)
    {
        $data = $r->validate(['email' => 'required|email:rfc|max:254']);

        return $this->workspace($workspace, function ($tenant) use ($r, $data) {
            // Log/array transports must never expose production login secrets in logs.
            $transport = config('mail.mailers.'.config('mail.default').'.transport');
            abort_unless(app()->runningUnitTests() || in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'sendmail'], true), 503, 'Candidate email delivery is not configured.');
            abort_unless(app()->runningUnitTests() || ! in_array(config('queue.default'), ['sync', 'null'], true), 503, 'Candidate email queue is not configured.');
            $nonce = $r->session()->get('candidate_portal_browser') ?? Str::random(64);
            $r->session()->put('candidate_portal_browser', $nonce);
            $hash = CandidateService::emailHash($data['email']);
            $candidate = $this->candidates()->where('email_hash', $hash)->first();
            if ($candidate) {
                $token = bin2hex(random_bytes(32));
                $challenge = DB::transaction(function () use ($candidate, $hash, $nonce, $token) {
                    Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    PortalChallenge::where('candidate_id', $candidate->id)->where('browser_hash', hash('sha256', $nonce))->delete();

                    return PortalChallenge::create(['candidate_id' => $candidate->id, 'email_hash' => $hash, 'token_hash' => hash('sha256', $token), 'browser_hash' => hash('sha256', $nonce), 'expires_at' => now()->addMinutes(15)]);
                });
                try {
                    SendCandidatePortalCode::dispatch($tenant->id, $challenge->id, $token)->afterCommit();
                } catch (\Throwable $error) {
                    $challenge->delete();
                    // Do not log message bodies, transport exceptions or raw tokens.
                    Log::warning('Candidate portal delivery failed', ['tenant_id' => $tenant->id]);
                }
            }

            return response()->json(['message' => 'If an eligible candidate record exists, a verification code will be emailed. Use the same browser to verify it.'], 202)->header('Cache-Control', 'no-store');
        });
    }

    public function verify(Request $r, string $workspace)
    {
        $data = $r->validate(['email' => 'required|email:rfc|max:254', 'code' => 'required|string|size:64|regex:/^[a-f0-9]+$/']);

        return $this->workspace($workspace, function ($tenant) use ($r, $data) {
            return DB::transaction(function () use ($tenant, $r, $data) {
                $challenge = PortalChallenge::where('token_hash', hash('sha256', $data['code']))->lockForUpdate()->first();
                abort_unless($challenge && $challenge->expires_at->isFuture() && hash_equals($challenge->email_hash, CandidateService::emailHash($data['email'])) && hash_equals($challenge->browser_hash, hash('sha256', (string) $r->session()->get('candidate_portal_browser'))), 422, 'The verification code is invalid or expired.');
                $candidate = $this->candidates()->whereKey($challenge->candidate_id)->where('email_hash', $challenge->email_hash)->first();
                abort_unless($candidate, 422, 'The verification code is invalid or expired.');
                $challenge->delete();
                $r->session()->migrate(true);
                $r->session()->put('candidate_portal', ['tenant_id' => $tenant->id, 'candidate_id' => $candidate->id, 'email_hash' => $candidate->email_hash, 'expires' => now()->addHour()->timestamp]);
                $this->record('candidate.portal_verified', $candidate);

                return response()->json(['verified' => true])->header('Cache-Control', 'no-store');
            });
        });
    }

    public function status(Request $r, string $workspace)
    {
        return $this->workspace($workspace, function ($tenant) use ($r) {
            $candidate = $this->sessionCandidate($r, $tenant);
            $this->record('candidate.portal_viewed', $candidate);
            $applications = Application::where('candidate_id', $candidate->id)->whereHas('requisition', fn ($q) => $q->where('confidential', false))->with('requisition.job')->latest('id')->get()
                ->map(fn ($a) => ['uuid' => $a->uuid, 'can_withdraw' => $a->status === 'active' && ! Offer::where('application_id', $a->id)->where('status', 'accepted')->exists(), 'job_title' => $a->requisition->job->title, 'status' => match ($a->status) {
                    'hired' => 'Hired', 'rejected' => 'Not selected', 'withdrawn' => 'Withdrawn', default => 'Under review'
                }, 'submitted_at' => $a->created_at]);

            $offers = Offer::whereIn('application_id', Application::where('candidate_id', $candidate->id)->whereHas('requisition', fn ($q) => $q->where('confidential', false))->select('id'))->whereIn('status', ['sent', 'accepted', 'declined'])->get()->map(function ($offer) {
                $release = \App\Domain\Recruitment\Models\OfferRelease::where('offer_id', $offer->id)->latest('version')->first();
                if (!$release || !$release->content) return null;
                return ['uuid' => $offer->uuid, 'job_title' => $offer->job_title, 'status' => $offer->status, 'release_uuid' => $release->uuid, 'release_version' => $release->version, 'content' => $release->content, 'can_respond' => $offer->status === 'sent' && $offer->application->status === 'active' && (!$offer->expires_on || $offer->expires_on->toDateString() >= now('UTC')->toDateString())];
            })->filter()->values();
            $resumes = \App\Domain\Recruitment\Models\Resume::where('candidate_id', $candidate->id)->orderByDesc('version')->get()->map(fn ($resume) => app(ResumeService::class)->present($resume))->values();
            return response()->json(['name' => $candidate->name, 'applications' => $applications, 'offers' => $offers, 'resumes' => $resumes])->header('Cache-Control', 'no-store');
        });
    }

    public function logout(Request $r)
    {
        $r->session()->forget(['candidate_portal', 'candidate_portal_browser']);
        $r->session()->migrate(true);

        return response()->noContent();
    }

    private function sessionCandidate(Request $r, Tenant $tenant): Candidate
    {
        $session = $r->session()->get('candidate_portal');
        abort_unless($session && $session['tenant_id'] === $tenant->id && $session['expires'] > now()->timestamp, 401, 'Verify your email to view applications.');
        $candidate = $this->candidates()->whereKey($session['candidate_id'])->where('email_hash', $session['email_hash'])->first();
        abort_unless($candidate, 401, 'Verify your email to view applications.');

        return $candidate;
    }

    public function respondOffer(Request $r, string $workspace, string $uuid)
    {
        $data = $r->validate(['decision' => 'required|in:accepted,declined', 'release_uuid' => 'required|uuid', 'confirmed' => 'required|accepted']);
        return $this->workspace($workspace, function ($tenant) use ($r, $uuid, $data) {
            return DB::transaction(function () use ($tenant, $r, $uuid, $data) {
                DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
                $candidate = $this->sessionCandidate($r, $tenant);
                $offer = Offer::where('uuid', $uuid)->whereIn('application_id', Application::where('candidate_id', $candidate->id)->whereHas('requisition', fn ($q) => $q->where('confidential', false))->select('id'))->lockForUpdate()->firstOrFail();
                if ($offer->candidate_response_release === $data['release_uuid'] && $offer->status === $data['decision']) return ['status' => $offer->status];
                abort_unless($offer->status === 'sent' && $offer->application->status === 'active', 409, 'This offer is no longer awaiting a response.');
                abort_if($offer->expires_on && $offer->expires_on->toDateString() < now('UTC')->toDateString(), 409, 'This offer expired. Contact the recruiter.');
                $release = \App\Domain\Recruitment\Models\OfferRelease::where('offer_id', $offer->id)->latest('version')->first();
                abort_unless($release && $release->content && $release->uuid === $data['release_uuid'], 409, 'The released terms changed. Refresh and review them before responding.');
                $offer->update(['status' => $data['decision'], 'responded_at' => now(), 'candidate_response_release' => $release->uuid]);
                if ($data['decision'] === 'accepted') {
                    foreach (RecruitmentNotify::holders('recruitment.handover') as $holder) {
                        if (app(\App\Domain\Recruitment\HiringScope::class)->applications($holder, 'recruitment.handover')->whereKey($offer->application_id)->exists()) RecruitmentNotify::task($holder->id, 'Complete pre-joiner handover', 'An offer was accepted. Create the employee record.', 'handover:'.$offer->uuid, '/recruitment', 'high');
                    }
                }
                RecruitmentNotify::tell($offer->application->requisition->recruiter_id, 'Offer '.$data['decision'], 'The candidate responded through the verified portal.');
                AuditChain::append(['user_id' => null, 'actor' => 'Candidate', 'action' => 'offer.candidate_'.$data['decision'], 'module' => 'recruitment', 'resource' => $offer->uuid, 'ip_address' => $r->ip(), 'context' => ['candidate' => $candidate->uuid, 'release' => $release->uuid, 'version' => $release->version]]);
                return ['status' => $offer->status];
            });
        });
    }

    public function uploadResume(Request $r, string $workspace, ResumeService $resumes)
    {
        $r->validate(['file' => 'required|file']);

        return $this->workspace($workspace, function ($tenant) use ($r, $resumes) {
            $candidate = $this->sessionCandidate($r, $tenant);
            $resume = $resumes->uploadFromPortal($candidate, $r->file('file'));
            RecruitmentNotify::tell($candidate->created_by, 'Candidate resume uploaded', 'A candidate uploaded a resume through the verified portal.');

            return response()->json($resumes->present($resume), 201)->header('Cache-Control', 'no-store');
        });
    }

    public function withdraw(Request $r, string $workspace, string $uuid)
    {
        $data = $r->validate(['confirmed' => 'required|accepted', 'reason' => 'required|string|max:2000']);

        return $this->workspace($workspace, function ($tenant) use ($r, $uuid, $data) {
            return DB::transaction(function () use ($tenant, $r, $uuid, $data) {
                DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
                $candidate = $this->sessionCandidate($r, $tenant);
                $application = Application::where('uuid', $uuid)->where('candidate_id', $candidate->id)->whereHas('requisition', fn ($q) => $q->where('confidential', false))->firstOrFail();
                $offers = Offer::where('application_id', $application->id)->orderBy('id')->lockForUpdate()->get();
                $application = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
                if ($application->status === 'withdrawn') {
                    return ['status' => 'Withdrawn'];
                }
                abort_unless($application->status === 'active' && ! $application->employee_id && ! $offers->contains('status', 'accepted'), 409, 'This application cannot be withdrawn online. Contact the recruiter.');
                $tasks = app(TaskService::class);
                foreach ($offers as $offer) {
                    if (in_array($offer->status, OfferService::OPEN, true)) {
                        $offer->update(['status' => 'withdrawn']);
                        $tasks->completeBySource('recruitment', 'offer:'.$offer->uuid);
                    }
                }
                $interviews = Interview::where('application_id', $application->id)->where('status', 'scheduled')->lockForUpdate()->get();
                foreach ($interviews as $interview) {
                    $interview->update(['status' => 'cancelled']);
                    foreach ($interview->panelists as $panelist) {
                        $tasks->completeBySource('recruitment', 'scorecard:'.$interview->uuid.':'.$panelist->user_id);
                        RecruitmentNotify::tell($panelist->user_id, 'Interview cancelled', 'The candidate withdrew their application.');
                    }
                }
                ApplicationAction::create(['application_id' => $application->id, 'actor_id' => null, 'candidate_actor_id' => $candidate->id, 'from_stage' => $application->stage, 'to_stage' => $application->stage, 'status' => 'withdrawn', 'reason' => $data['reason'], 'idempotency_key' => (string) Str::uuid()]);
                $application->update(['status' => 'withdrawn', 'closed_at' => now()]);
                RecruitmentNotify::tell($application->requisition->recruiter_id, 'Candidate withdrew application', 'A candidate withdrew through the verified portal.');
                AuditChain::append(['user_id' => null, 'actor' => 'Candidate', 'action' => 'application.candidate_withdrawn', 'module' => 'recruitment', 'resource' => $application->uuid, 'ip_address' => $r->ip(), 'context' => ['candidate' => $candidate->uuid]]);

                return ['status' => 'Withdrawn'];
            });
        });
    }
}
