<?php

namespace App\Domain\Tenancy;

use App\Models\Plan;
use App\Models\Tenant;

/** Free-trial and owner-approval lifecycle of a workspace: trial -> suspended -> (owner) active. */
final class AccountLifecycle
{
    public const TRIAL_HOURS = 24;

    public const STATUSES = ['trial', 'active', 'suspended', 'rejected', 'deactivated'];

    /** Owner action => resulting status. */
    public const ACTIONS = ['approve' => 'active', 'activate' => 'active', 'reject' => 'rejected', 'suspend' => 'suspended', 'deactivate' => 'deactivated'];

    public function startTrial(Tenant $t): Tenant
    {
        $t->forceFill(['status' => 'trial', 'trial_started_at' => now(), 'trial_ends_at' => now()->addHours(self::TRIAL_HOURS), 'plan' => 'trial', 'status_changed_at' => now()])->save();

        return $t;
    }

    /** Suspends a trial whose 24 hours are over. Safe to call on every request. */
    public function refresh(Tenant $t): Tenant
    {
        if ($t->status === 'trial' && $t->trial_ends_at && $t->trial_ends_at->lte(now())) {
            $t->forceFill(['status' => 'suspended', 'status_reason' => 'trial_expired', 'status_changed_at' => now()])->save();
        }

        return $t;
    }

    /** Scheduled sweep so expired trials are suspended even if nobody signs in. */
    public function expireDue(): int
    {
        return Tenant::where('status', 'trial')->where('is_platform', false)->where('trial_ends_at', '<=', now())
            ->update(['status' => 'suspended', 'status_reason' => 'trial_expired', 'status_changed_at' => now()]);
    }

    public function hasAccess(Tenant $t): bool
    {
        $this->refresh($t);

        return in_array($t->status, ['trial', 'active'], true);
    }

    public function transition(Tenant $t, string $action, int $ownerId, ?string $note = null, array $subscription = []): Tenant
    {
        $status = self::ACTIONS[$action];
        $attrs = ['status' => $status, 'status_reason' => $action === 'approve' ? null : $action, 'status_note' => $note, 'status_changed_at' => now()];
        if ($status === 'active') {
            $attrs += ['approved_at' => now(), 'approved_by' => $ownerId, 'plan' => $subscription['plan'] ?? ($t->plan === 'trial' ? 'standard' : $t->plan)];
            if (array_key_exists('subscription_ends_at', $subscription)) {
                $attrs['subscription_ends_at'] = $subscription['subscription_ends_at'];
            }
        }
        $t->forceFill($attrs)->save();
        if ($status === 'active' && ! empty($subscription['plan'])) {
            $this->applyPlan($t, $subscription['plan']);
        }

        return $t;
    }

    /** Sets the company's plan and unlocks exactly that plan's modules. Unknown plan codes only label the account. */
    public function applyPlan(Tenant $t, string $code): Tenant
    {
        $t->forceFill(['plan' => $code]);
        if ($plan = Plan::where('code', $code)->first()) {
            $t->settings = array_merge($t->settings ?? [], ['modules' => $plan->allModules()]);
        }
        $t->save();

        return $t;
    }

    /** What the signed-in client needs to render the trial banner or the suspended screen. */
    public function summary(Tenant $t): array
    {
        $this->refresh($t);

        return ['status' => $t->status, 'reason' => $t->status_reason, 'note' => $t->status_note, 'plan' => $t->plan, 'trial_ends_at' => $t->trial_ends_at, 'subscription_ends_at' => $t->subscription_ends_at, 'access' => in_array($t->status, ['trial', 'active'], true)];
    }
}
