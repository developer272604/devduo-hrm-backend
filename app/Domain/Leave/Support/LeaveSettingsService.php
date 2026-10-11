<?php

namespace App\Domain\Leave\Support;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Tenant-scoped, validated administration of the leave settings block. */
final class LeaveSettingsService
{
    public function show(): array
    {
        return LeaveSettings::all();
    }

    public function update(array $input): array
    {
        $allowed = array_keys(Arr::dot(LeaveSettings::DEFAULTS));
        $unknown = array_values(array_filter(array_keys(Arr::dot($input)), fn ($key) => ! in_array($key, $allowed, true)));
        if ($unknown) {
            throw ValidationException::withMessages(['settings' => 'Unknown setting: '.implode(', ', $unknown).'.']);
        }

        Validator::make($input, [
            'units.minutes_per_day' => ['sometimes', 'integer', 'between:1,1440'],
            'units.minutes_per_half_day' => ['sometimes', 'integer', 'between:1,1440'],
            'units.minutes_per_quarter_day' => ['sometimes', 'integer', 'between:1,1440'],
            'units.rounding' => ['sometimes', 'string', Rule::in(['floor', 'ceil', 'nearest'])],
            'balance.allow_negative_default' => ['sometimes', 'boolean'],
            'balance.default_negative_limit' => ['sometimes', 'numeric', 'min:0', 'max:365'],
            'balance.carry_negative_forward' => ['sometimes', 'boolean'],
            'balance.maker_checker' => ['sometimes', 'boolean'],
            'requests.backdate_days' => ['sometimes', 'integer', 'between:0,365'],
            'requests.future_days' => ['sometimes', 'integer', 'between:0,3650'],
            'requests.advance_notice_days' => ['sometimes', 'integer', 'between:0,365'],
            'requests.pending_expiry_days' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            'requests.allow_withdraw_after_approval' => ['sometimes', 'boolean'],
            'requests.require_reason' => ['sometimes', 'boolean'],
            'approvals.steps' => ['sometimes', 'array', 'min:1'],
            'approvals.steps.*' => ['string', Rule::in(['manager', 'hr'])],
            'approvals.escalate_after_hours' => ['sometimes', 'integer', 'between:1,8760'],
            'approvals.auto_approve_for_hr' => ['sometimes', 'boolean'],
            'accrual.run_day_of_month' => ['sometimes', 'integer', 'between:1,31'],
            'accrual.catch_up_days' => ['sometimes', 'integer', 'between:0,365'],
            'accrual.nightly_enabled' => ['sometimes', 'boolean'],
            'accrual.auto_carry_forward' => ['sometimes', 'boolean'],
            'expiry.notify_days_before' => ['sometimes', 'array'],
            'expiry.notify_days_before.*' => ['integer', 'between:1,365'],
            'comp_off.require_approval' => ['sometimes', 'boolean'],
            'comp_off.default_validity_days' => ['sometimes', 'integer', 'between:1,3650'],
            'privacy.managers_see_reason' => ['sometimes', 'boolean'],
            'privacy.managers_see_medical' => ['sometimes', 'boolean'],
            'privacy.medical_retention_days' => ['sometimes', 'integer', 'between:1,36500'],
            'restrictions.never_block_statutory' => ['sometimes', 'boolean'],
            'integration.sync_attendance' => ['sometimes', 'boolean'],
            'integration.payroll_contract' => ['sometimes', 'boolean'],
            'integration.cutoff_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
        ])->validate();

        $tenantId = app(TenantContext::class)->id;
        abort_unless($tenantId, 404);

        return DB::transaction(function () use ($tenantId, $input) {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $settings = $tenant->settings ?? [];
            $settings['leave'] = $this->merge((array) ($settings['leave'] ?? []), $input);
            $tenant->settings = $settings;
            $tenant->save();

            return LeaveSettings::all($tenantId);
        });
    }

    private function merge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null)
                ? $this->merge($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
