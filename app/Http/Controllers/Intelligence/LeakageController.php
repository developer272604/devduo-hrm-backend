<?php

namespace App\Http\Controllers\Intelligence;

use App\Domain\Access\DataScope;
use App\Domain\Intelligence\Leakage\LeakageCaseService;
use App\Domain\Intelligence\Leakage\LeakageDetector;
use App\Domain\Intelligence\Leakage\LeakageRules;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Workforce Cost Leakage Investigator: scan, review lifecycle, assignment, rule thresholds. */
final class LeakageController
{
    public function __construct(private LeakageCaseService $cases, private DataScope $scope) {}

    public function index(Request $r)
    {
        $d = $r->validate(['status' => 'nullable|in:active,all,open,under_review,confirmed,false_positive,recovered', 'rule' => 'nullable|string|in:'.implode(',', array_keys(LeakageRules::CATALOG))]);

        return $this->cases->list($r->user(), $d) + ['generated_at' => now()->toIso8601String()];
    }

    public function scan(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);

        return ['scan' => $this->cases->scan($r->user(), $d['from'] ?? null, $d['to'] ?? null)] + $this->cases->list($r->user(), []);
    }

    public function show(Request $r, string $uuid)
    {
        $c = $this->cases->find($r->user(), $uuid);

        return $this->cases->present($c) + ['history' => $this->cases->history($c)];
    }

    public function explain(Request $r, string $uuid)
    {
        return $this->cases->explainFor($this->cases->find($r->user(), $uuid));
    }

    public function status(Request $r, string $uuid)
    {
        $d = $r->validate(['status' => 'required|in:'.implode(',', LeakageCaseService::STATUSES), 'reason' => 'nullable|string|max:2000', 'recovered_amount' => 'nullable|numeric|min:0']);

        return $this->cases->present($this->cases->transition($r->user(), $this->cases->find($r->user(), $uuid), $d['status'], $d['reason'] ?? null, isset($d['recovered_amount']) ? (float) $d['recovered_amount'] : null));
    }

    public function assign(Request $r, string $uuid)
    {
        $d = $r->validate(['owner_user_id' => 'required|integer']);
        $owner = User::where('tenant_id', $r->user()->tenant_id)->find($d['owner_user_id']);
        if (! $owner || ! $owner->allows(LeakageDetector::PERMISSION)) {
            throw ValidationException::withMessages(['owner_user_id' => 'Choose a user who can review leakage cases.']);
        }

        return $this->cases->assign($r->user(), $this->cases->find($r->user(), $uuid), $owner);
    }

    public function settings()
    {
        return $this->cases->settings();
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['thresholds' => 'array', 'thresholds.ot_spike_ratio' => 'numeric|min:1|max:20', 'thresholds.ot_spike_min_minutes' => 'integer|min:0|max:100000',
            'thresholds.ot_over_calculated_min_minutes' => 'integer|min:0|max:100000', 'thresholds.regularization_min_count' => 'integer|min:1|max:100',
            'thresholds.regularization_overtime_min_minutes' => 'integer|min:0|max:100000', 'thresholds.repeat_adjustment_min_count' => 'integer|min:1|max:100',
            'disabled_rules' => 'array', 'disabled_rules.*' => 'string|in:'.implode(',', array_keys(LeakageRules::CATALOG))]);

        return $this->cases->saveSettings($r->user(), $d['thresholds'] ?? [], $d['disabled_rules'] ?? []);
    }
}
