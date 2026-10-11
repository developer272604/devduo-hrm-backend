<?php

namespace App\Domain\Security;

use App\Domain\Audit\Audit;
use App\Domain\Security\Models\AccessReviewCampaign;
use App\Domain\Security\Models\AccessReviewItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccessReviewService
{
    public function create(User $actor, array $input): AccessReviewCampaign
    {
        $data = validator($input, ['name' => 'required|string|max:150', 'due_on' => 'required|date_format:Y-m-d|after:today'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $campaign = AccessReviewCampaign::create(['name' => $data['name'], 'due_on' => $data['due_on'], 'status' => 'open', 'created_by' => $actor->id]);
            User::where('tenant_id', $actor->tenant_id)->where('active', true)->with('roles:id,name,data_scope')->orderBy('id')->chunkById(200, function ($users) use ($campaign) {
                foreach ($users as $user) {
                    AccessReviewItem::create(['campaign_id' => $campaign->id, 'user_id' => $user->id, 'status' => 'pending',
                        'role_snapshot' => $user->roles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name, 'data_scope' => $role->data_scope])->values()->all()]);
                }
            });
            Audit::record('security.access_review.created', 'security', 'campaign:'.$campaign->uuid, ['due_on' => $campaign->due_on->toDateString()]);

            return $campaign->refresh();
        });
    }

    public function campaigns(): array
    {
        return AccessReviewCampaign::latest('id')->paginate(25)->through(function ($campaign) {
            $counts = AccessReviewItem::where('campaign_id', $campaign->id)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
            return ['uuid' => $campaign->uuid, 'name' => $campaign->name, 'status' => $campaign->status, 'due_on' => $campaign->due_on->toDateString(),
                'counts' => ['total' => (int) $counts->sum(), 'pending' => (int) ($counts['pending'] ?? 0), 'confirmed' => (int) ($counts['confirmed'] ?? 0),
                    'revoke_requested' => (int) ($counts['revoke_requested'] ?? 0), 'exception_accepted' => (int) ($counts['exception_accepted'] ?? 0)]];
        })->toArray();
    }

    public function items(string $uuid): array
    {
        $campaign = AccessReviewCampaign::where('uuid', $uuid)->firstOrFail();
        return AccessReviewItem::where('campaign_id', $campaign->id)->with('user:id,name,email')->orderBy('id')->paginate(50)->through(fn ($item) => [
            'uuid' => $item->uuid, 'user' => ['id' => $item->user->id, 'name' => $item->user->name, 'email' => $item->user->email],
            'role_snapshot' => $item->role_snapshot, 'status' => $item->status, 'note' => $item->note,
            'reviewed_at' => $item->reviewed_at?->toIso8601String(),
        ])->toArray();
    }

    public function review(User $actor, string $campaignUuid, string $itemUuid, array $input): AccessReviewItem
    {
        $data = validator($input, ['decision' => 'required|in:confirmed,revoke_requested,exception_accepted', 'note' => 'nullable|string|max:1000'])->validate();
        if (in_array($data['decision'], ['revoke_requested', 'exception_accepted'], true) && trim($data['note'] ?? '') === '') {
            throw ValidationException::withMessages(['note' => 'Add a reason for this access review decision.']);
        }

        return DB::transaction(function () use ($actor, $campaignUuid, $itemUuid, $data) {
            $campaign = AccessReviewCampaign::where('uuid', $campaignUuid)->lockForUpdate()->firstOrFail();
            abort_unless($campaign->status === 'open', 409, 'This review campaign is closed.');
            $item = AccessReviewItem::where('campaign_id', $campaign->id)->where('uuid', $itemUuid)->lockForUpdate()->firstOrFail();
            abort_unless($item->user_id !== $actor->id, 403, 'Review your own access through a different authorized reviewer.');
            abort_unless($item->status === 'pending', 409, 'This access item has already been reviewed.');
            $item->update(['status' => $data['decision'], 'note' => isset($data['note']) ? trim($data['note']) : null, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            if (! AccessReviewItem::where('campaign_id', $campaign->id)->where('status', 'pending')->exists()) {
                $campaign->update(['status' => 'completed', 'completed_at' => now()]);
            }
            Audit::record('security.access_review.item_reviewed', 'security', 'access_review_item:'.$item->uuid, ['decision' => $data['decision']]);

            return $item->refresh();
        });
    }
}
