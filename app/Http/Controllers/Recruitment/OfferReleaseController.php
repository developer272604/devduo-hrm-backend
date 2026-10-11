<?php
namespace App\Http\Controllers\Recruitment;
use App\Domain\Audit\Audit;
use App\Domain\Recruitment\Models\OfferRelease;
use App\Domain\Recruitment\OfferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class OfferReleaseController {
    public function store(Request $r, string $uuid) {
        $data = $r->validate(['content' => 'required|string|max:20000', 'confirmed' => 'required|accepted']);
        return DB::transaction(function () use ($r, $uuid, $data) {
            DB::table('tenants')->where('id', $r->user()->tenant_id)->lockForUpdate()->first();
            $offer = app(OfferService::class)->find($r->user(), $uuid, 'recruitment.offer.approve', true);
            abort_if($offer->created_by === $r->user()->id, 403, 'An independent approver must release candidate-facing terms.');
            abort_unless($offer->status === 'sent' && $offer->application->status === 'active' && !$offer->application->requisition->confidential, 409, 'Only sent offers on active non-confidential applications can be released.');
            $candidate = $offer->application->candidate;
            abort_if($candidate->anonymized_at || $candidate->retention_until < now('UTC')->toDateString(), 409, 'Candidate data is no longer available.');
            abort_if($offer->expires_on && $offer->expires_on->toDateString() < now('UTC')->toDateString(), 409, 'The offer expired.');
            $release = OfferRelease::create(['offer_id' => $offer->id, 'version' => (OfferRelease::where('offer_id', $offer->id)->max('version') ?? 0) + 1, 'content' => $data['content'], 'released_by' => $r->user()->id]);
            Audit::record('offer.candidate_terms_released', 'recruitment', $offer->uuid, ['release' => $release->uuid, 'version' => $release->version]);
            return response()->json(['uuid' => $release->uuid, 'version' => $release->version], 201);
        });
    }
}
