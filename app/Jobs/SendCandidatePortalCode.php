<?php

namespace App\Jobs;

use App\Domain\Recruitment\Models\Candidate;
use App\Domain\Recruitment\Models\PortalChallenge;
use App\Domain\Tenancy\TenantRunner;
use App\Mail\CandidatePortalCode;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendCandidatePortalCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $tenantId, public int $challengeId, public string $token) {}

    public function handle(TenantRunner $runner): void
    {
        $runner->run($this->tenantId, function () {
            if (! config('recruitment.candidate_portal_enabled')) {
                return;
            }
            $transport = config('mail.mailers.'.config('mail.default').'.transport');
            if (! app()->runningUnitTests() && ! in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'sendmail'], true)) {
                throw new \RuntimeException('Candidate portal requires a delivering mail transport.');
            }
            $tenant = Tenant::findOrFail($this->tenantId);
            if (! in_array('recruitment', $tenant->settings['modules'] ?? [], true)) {
                return;
            }
            $challenge = PortalChallenge::whereKey($this->challengeId)->where('expires_at', '>', now())->first();
            if (! $challenge || ! hash_equals($challenge->token_hash, hash('sha256', $this->token))) {
                return;
            }
            $candidate = Candidate::whereKey($challenge->candidate_id)->where('email_hash', $challenge->email_hash)->whereNull('anonymized_at')->where('retention_until', '>=', now('UTC')->toDateString())->first();
            if (! $candidate) {
                return;
            }
            try {
                Mail::to($candidate->email)->send(new CandidatePortalCode($this->token, $tenant->name));
            } catch (\Throwable $error) {
                // Never persist a transport exception containing recipient or message secrets.
                throw new \RuntimeException('Candidate portal email delivery failed. Check mail transport configuration.');
            }
        });
    }
}
