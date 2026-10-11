<?php

namespace App\Domain\Security;

use App\Domain\Audit\Audit;
use App\Domain\Security\Contracts\LegalHoldChecker;
use App\Domain\Security\Models\LegalHold;
use App\Domain\Security\Models\RetentionPolicy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class LegalHoldService implements LegalHoldChecker
{
    public const MODULES = ['people', 'attendance', 'leave', 'payroll', 'recruitment', 'lifecycle', 'performance', 'learning', 'experience', 'assets', 'expenses', 'travel', 'workflows', 'security'];

    /** Static convenience for purge jobs: fails closed (treated as held) if the check itself breaks. */
    public static function held(string $module, ?string $subjectRef = null): bool
    {
        if (! app(TenantContext::class)->id) {
            return true;
        }
        try {
            return app(self::class)->isHeld($module, $subjectRef);
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }

    public function isHeld(string $module, ?string $subjectRef = null): bool
    {
        return LegalHold::whereNull('released_at')
            ->where(fn ($q) => $q->whereNull('module')->orWhere('module', $module))
            ->where(fn ($q) => $subjectRef === null ? $q->whereNull('subject_ref') : $q->whereNull('subject_ref')->orWhere('subject_ref', $subjectRef))
            ->exists();
    }

    public function holds(): array
    {
        return LegalHold::orderByDesc('id')->get()->map(fn (LegalHold $h) => $this->present($h))->all();
    }

    public function place(User $actor, array $input): LegalHold
    {
        $data = validator($input, ['name' => 'required|string|max:150', 'reason' => 'required|string|min:5|max:2000', 'module' => 'nullable|in:'.implode(',', self::MODULES),
            'subject_ref' => 'nullable|string|max:100|regex:/^[a-z_]+:[A-Za-z0-9._-]+$/'])->validate();
        if (($data['subject_ref'] ?? null) && empty($data['module'])) {
            throw ValidationException::withMessages(['module' => 'Choose the module the record belongs to.']);
        }
        $hold = LegalHold::create(['name' => $data['name'], 'reason' => $data['reason'], 'module' => $data['module'] ?? null, 'subject_ref' => $data['subject_ref'] ?? null, 'placed_by' => $actor->id, 'placed_at' => now()]);
        Audit::record('security.legal_hold.placed', 'security', 'legal_hold:'.$hold->uuid, ['module' => $hold->module, 'subject_ref' => $hold->subject_ref]);

        return $hold;
    }

    public function release(User $actor, string $uuid, string $reason): LegalHold
    {
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();
        $hold = LegalHold::byUuid($uuid);
        abort_if($hold->released_at, 409, 'This hold was already released.');
        $hold->update(['released_at' => now(), 'released_by' => $actor->id, 'release_reason' => $reason]);
        Audit::record('security.legal_hold.released', 'security', 'legal_hold:'.$hold->uuid);

        return $hold->refresh();
    }

    public function retention(): array
    {
        return RetentionPolicy::orderBy('module')->orderBy('data_category')->get()->map(fn (RetentionPolicy $p) => [
            'uuid' => $p->uuid, 'data_category' => $p->data_category, 'module' => $p->module, 'retention_days' => $p->retention_days, 'action' => $p->action,
            'enforced_by' => $p->enforced_by, 'enabled' => $p->enabled, 'notes' => $p->notes, 'held' => $this->isHeld($p->module),
        ])->all();
    }

    public function saveRetention(User $actor, array $input, ?string $uuid = null): RetentionPolicy
    {
        $data = validator($input, ['data_category' => 'required|string|max:100', 'module' => 'required|in:'.implode(',', self::MODULES), 'retention_days' => 'required|integer|min:1|max:36500',
            'action' => 'required|in:review,purge,anonymize', 'enforced_by' => 'nullable|string|max:120', 'enabled' => 'boolean', 'notes' => 'nullable|string|max:2000'])->validate();
        $policy = $uuid ? RetentionPolicy::byUuid($uuid) : null;
        $duplicate = RetentionPolicy::where('data_category', $data['data_category'])->when($policy, fn ($q) => $q->whereKeyNot($policy->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['data_category' => 'A policy for this data category already exists.']);
        }
        $policy ? $policy->update($data) : $policy = RetentionPolicy::create($data);
        Audit::record($uuid ? 'security.retention.updated' : 'security.retention.created', 'security', 'retention_policy:'.$policy->uuid, ['retention_days' => $policy->retention_days, 'action' => $policy->action]);

        return $policy->refresh();
    }

    public function present(LegalHold $h): array
    {
        return ['uuid' => $h->uuid, 'name' => $h->name, 'reason' => $h->reason, 'module' => $h->module, 'subject_ref' => $h->subject_ref, 'active' => $h->released_at === null,
            'placed_at' => $h->placed_at->toIso8601String(), 'released_at' => $h->released_at?->toIso8601String(), 'release_reason' => $h->release_reason];
    }
}
