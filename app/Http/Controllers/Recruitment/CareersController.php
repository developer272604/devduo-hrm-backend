<?php

namespace App\Http\Controllers\Recruitment;

use App\Domain\Audit\Audit;
use App\Domain\Recruitment\CandidateService;
use App\Domain\Recruitment\HiringScope;
use App\Domain\Recruitment\HiringService;
use App\Domain\Recruitment\Models\Posting;
use App\Domain\Recruitment\Models\Submission;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CareersController
{
    private function workspace(string $slug, callable $action): mixed
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        abort_unless(in_array('recruitment', $tenant->settings['modules'] ?? [], true) && app(\App\Domain\Tenancy\AccountLifecycle::class)->hasAccess($tenant), 404);

        return app(TenantRunner::class)->run($tenant->id, fn () => $action($tenant));
    }

    private function visible()
    {
        return Posting::where('public_enabled', true)->where('channel', 'external')->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('closes_on')->orWhere('closes_on', '>=', now('UTC')->toDateString()))
            ->whereHas('requisition', fn ($q) => $q->where('status', 'open')->where('confidential', false)->whereColumn('filled_count', '<', 'openings'));
    }

    public function index(Request $r, string $workspace)
    {
        $r->validate(['q' => 'nullable|string|max:100']);

        return $this->workspace($workspace, fn ($tenant) => ['company' => $tenant->name, 'jobs' => $this->visible()
            ->when($r->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$r->input('q').'%'))
            ->latest('id')->paginate(20)->through(fn ($p) => $p->only(['uuid', 'title', 'description', 'location_text', 'closes_on', 'privacy_notice', 'notice_version']))]);
    }

    public function apply(Request $r, string $workspace, string $uuid)
    {
        $data = $r->validate(['name' => 'required|string|max:160', 'email' => 'required|email:rfc|max:254', 'phone' => 'nullable|string|max:40', 'consent' => 'required|accepted', 'notice_version' => 'required|string|max:80']);

        return $this->workspace($workspace, function ($tenant) use ($data, $uuid) {
            return DB::transaction(function () use ($tenant, $data, $uuid) {
                DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
                $posting = $this->visible()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
                abort_unless(hash_equals($posting->notice_version, $data['notice_version']), 409, 'The privacy notice changed. Refresh and review it before applying.');
                Submission::create(['posting_id' => $posting->id, 'expires_at' => now()->addDays($posting->retention_days), 'payload' => $data + ['privacy_notice' => $posting->privacy_notice, 'consented_at' => now('UTC')->toIso8601String()]]);

                // No candidate lookup or identity linkage: anonymous input proves no email ownership.
                return response()->json(['message' => 'Your submission has been received for recruiter review.'], 202);
            });
        });
    }

    public function configure(Request $r, string $uuid, HiringScope $scope)
    {
        $data = $r->validate(['public_enabled' => 'required|boolean', 'privacy_notice' => 'required_if:public_enabled,true|nullable|string|max:10000', 'notice_version' => 'required_if:public_enabled,true|nullable|string|max:80', 'retention_days' => 'required_if:public_enabled,true|nullable|integer|min:1|max:730']);

        return DB::transaction(function () use ($r, $uuid, $scope, $data) {
            DB::table('tenants')->where('id', $r->user()->tenant_id)->lockForUpdate()->first();
            $posting = Posting::where('uuid', $uuid)->whereIn('requisition_id', $scope->requisitions($r->user(), 'recruitment.manage')->select('id'))->lockForUpdate()->firstOrFail();
            if ($data['public_enabled']) {
                abort_unless($posting->channel === 'external' && ! $posting->requisition->confidential && $posting->requisition->status === 'open' && $posting->status === 'published', 422, 'Only published external postings on open, non-confidential requisitions can be public.');
                abort_if($posting->privacy_notice && $posting->privacy_notice !== $data['privacy_notice'] && $posting->notice_version === $data['notice_version'], 422, 'Change the notice version when changing its text.');
            }
            $posting->update($data);
            Audit::record('posting.public_visibility_changed', 'recruitment', $posting->uuid, ['enabled' => $data['public_enabled']]);

            return ['public_enabled' => (bool) $posting->public_enabled, 'careers_path' => '/careers/'.Tenant::findOrFail($r->user()->tenant_id)->slug];
        });
    }

    public function submissions(Request $r, HiringScope $scope)
    {
        Audit::record('submissions.viewed', 'recruitment', 'submission-list');

        return Submission::where('status', 'pending')->where('expires_at', '>', now())
            ->whereIn('posting_id', Posting::whereIn('requisition_id', $scope->requisitions($r->user(), 'recruitment.manage')->select('id'))->select('id'))
            ->latest('id')->paginate(25)->through(fn ($s) => ['uuid' => $s->uuid, 'name' => $s->payload['name'], 'email' => $s->payload['email'], 'phone' => $s->payload['phone'] ?? null, 'privacy_notice' => $s->payload['privacy_notice'], 'consented_at' => $s->payload['consented_at'], 'notice_version' => $s->payload['notice_version'], 'created_at' => $s->created_at]);
    }

    public function review(Request $r, string $uuid, HiringScope $scope)
    {
        $data = $r->validate(['decision' => 'required|in:accept,reject', 'identity_reviewed' => 'required_if:decision,accept|boolean']);

        return DB::transaction(function () use ($r, $uuid, $scope, $data) {
            DB::table('tenants')->where('id', $r->user()->tenant_id)->lockForUpdate()->first();
            $s = Submission::where('uuid', $uuid)->whereIn('posting_id', Posting::whereIn('requisition_id', $scope->requisitions($r->user(), 'recruitment.manage')->select('id'))->select('id'))->lockForUpdate()->firstOrFail();
            abort_unless($s->status === 'pending' && $s->expires_at->isFuture(), 409, 'Submission already reviewed or expired.');
            if ($data['decision'] === 'accept') {
                abort_unless($r->boolean('identity_reviewed'), 422, 'Confirm identity and consent review.');
                $p = Posting::findOrFail($s->posting_id);
                $candidate = app(CandidateService::class)->create($r->user(), [
                    'name' => $s->payload['name'], 'email' => $s->payload['email'], 'phone' => $s->payload['phone'] ?? null,
                    'source' => 'careers', 'privacy_notice_version' => $s->payload['notice_version'],
                    'consent_reference' => 'Careers submission '.$s->uuid, 'consented_at' => $s->payload['consented_at'], 'retention_until' => $s->expires_at->toDateString(),
                ]);
                app(HiringService::class)->apply($r->user(), ['candidate_uuid' => $candidate->uuid, 'requisition_uuid' => $p->requisition->uuid, 'source' => 'careers']);
            }
            $s->update(['status' => $data['decision'] === 'accept' ? 'accepted' : 'rejected', 'reviewed_by' => $r->user()->id, 'payload' => null]);
            Audit::record('submission.reviewed', 'recruitment', $s->uuid, ['decision' => $data['decision']]);

            return ['status' => $s->status];
        });
    }
}
