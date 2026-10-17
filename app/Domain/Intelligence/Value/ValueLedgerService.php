<?php

namespace App\Domain\Intelligence\Value;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Intelligence\Models\LeakageCase;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Workflow\Models\Definition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Value ledger: what the product has demonstrably saved, with the strength of each claim kept visible.
 *  - verified: leakage cases a person marked recovered, with the recovered amount (money actually back).
 *  - estimated: automation time saved = completed workflow runs x minutes per run, where the minutes are an assumption someone wrote down.
 *  - claimed: manual entries, each with a written basis.
 * The three are never added together into a single headline, so a number can always be traced to how sure we are of it.
 */
final class ValueLedgerService
{
    public const CATEGORIES = ['recovered_cost', 'time_saved', 'avoided_cost', 'other'];

    private const MAX_MONTHS = 24;

    public function __construct(private DataScope $scope) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** The ledger spans the whole company, so it is limited to people who hold the permission tenant-wide. */
    public function authorize(User $u, string $permission): void
    {
        abort_unless($u->allows($permission) && $this->scope->hasTenantWide($u, $permission), 403, 'The value ledger is company-wide and needs company-wide access.');
    }

    private function month(string $m): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            $this->fail('month', "Month \"$m\" must be YYYY-MM.");
        }
        $d = CarbonImmutable::parse($m.'-01');
        if ($d->lt(CarbonImmutable::parse('2000-01-01')) || $d->gt(CarbonImmutable::parse(TenantClock::today())->startOfMonth())) {
            $this->fail('month', "Month \"$m\" must be between 2000-01 and the current month.");
        }

        return $d;
    }

    // ---- assumptions -------------------------------------------------------------------------------------------------------------

    public function assumptions(): array
    {
        $set = ValueAssumption::all()->keyBy('definition_id');

        return Definition::orderBy('name')->get()->map(function ($d) use ($set) {
            $a = $set->get($d->id);

            return ['definition_uuid' => $d->uuid, 'name' => $d->name, 'published' => $d->published_version_id !== null,
                'assumption' => $a ? ['minutes_per_run' => $a->minutes_per_run, 'hourly_cost' => $a->hourly_cost !== null ? (float) $a->hourly_cost : null, 'currency' => $a->currency, 'basis' => $a->basis] : null];
        })->all();
    }

    public function setAssumption(User $u, string $definitionUuid, array $d): array
    {
        $def = Definition::where('uuid', $definitionUuid)->firstOrFail();
        if (mb_strlen(trim($d['basis'])) < 10) {
            $this->fail('basis', 'Explain how you arrived at the minutes (at least 10 characters), for example "timed 5 recent manual onboardings".');
        }
        if (isset($d['hourly_cost']) && empty($d['currency'])) {
            $this->fail('currency', 'Add a currency with the hourly cost.');
        }
        ValueAssumption::updateOrCreate(['definition_id' => $def->id], ['minutes_per_run' => $d['minutes_per_run'], 'hourly_cost' => $d['hourly_cost'] ?? null,
            'currency' => isset($d['currency']) ? strtoupper($d['currency']) : null, 'basis' => $d['basis'], 'set_by' => $u->id]);
        Audit::record('value.assumption.set', 'intelligence', 'workflow:'.$def->uuid, ['minutes' => $d['minutes_per_run']]);

        return collect($this->assumptions())->firstWhere('definition_uuid', $def->uuid);
    }

    public function clearAssumption(User $u, string $definitionUuid): void
    {
        $def = Definition::where('uuid', $definitionUuid)->firstOrFail();
        ValueAssumption::where('definition_id', $def->id)->delete();
        Audit::record('value.assumption.clear', 'intelligence', 'workflow:'.$def->uuid);
    }

    // ---- manual claims -----------------------------------------------------------------------------------------------------------

    public function addEntry(User $u, array $d): ValueEntry
    {
        $month = $this->month($d['month']);
        if (mb_strlen(trim($d['basis'])) < 10) {
            $this->fail('basis', 'Write the basis for this claim (at least 10 characters).');
        }
        if (! isset($d['amount']) && ! isset($d['hours'])) {
            $this->fail('amount', 'Enter an amount, hours, or both.');
        }
        if (isset($d['amount']) && empty($d['currency'])) {
            $this->fail('currency', 'Add a currency with the amount.');
        }
        $e = ValueEntry::create(['entry_month' => $month->toDateString(), 'category' => $d['category'], 'title' => $d['title'], 'basis' => $d['basis'], 'amount' => $d['amount'] ?? null,
            'currency' => isset($d['currency']) ? strtoupper($d['currency']) : null, 'hours' => $d['hours'] ?? null, 'created_by' => $u->id]);
        Audit::record('value.entry.add', 'intelligence', 'value:'.$e->uuid, ['category' => $d['category']]);

        return $e;
    }

    public function deleteEntry(User $u, string $uuid): void
    {
        $e = ValueEntry::where('uuid', $uuid)->firstOrFail();
        $e->delete();
        Audit::record('value.entry.delete', 'intelligence', 'value:'.$uuid);
    }

    public function presentEntry(ValueEntry $e): array
    {
        return ['uuid' => $e->uuid, 'month' => $e->entry_month->format('Y-m'), 'category' => $e->category, 'title' => $e->title, 'basis' => $e->basis, 'amount' => $e->amount !== null ? (float) $e->amount : null,
            'currency' => $e->currency, 'hours' => $e->hours !== null ? (float) $e->hours : null, 'by' => User::find($e->created_by)?->name, 'confidence' => 'claimed'];
    }

    // ---- report ------------------------------------------------------------------------------------------------------------------

    public function report(User $u, string $from, string $to): array
    {
        $start = $this->month($from);
        $end = $this->month($to)->endOfMonth();
        if ($end->lt($start) || $start->diffInMonths($end) + 1 > self::MAX_MONTHS) {
            $this->fail('to', 'Choose 1 to '.self::MAX_MONTHS.' months, ending on or after the start.');
        }
        $months = [];
        for ($m = $start; $m->lte($end); $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'verified_recovered' => [], 'estimated_hours_saved' => 0.0, 'estimated_value' => [], 'claimed' => ['amount' => [], 'hours' => 0.0, 'entries' => 0]];
        }
        // Verified: leakage cases marked recovered by a reviewer, counted in the month they were closed.
        $verifiedCases = LeakageCase::where('status', 'recovered')->whereNotNull('recovered_amount')->whereBetween('closed_at', [$start->startOfDay(), $end->endOfDay()])->get(['uuid', 'title', 'rule_code', 'currency', 'recovered_amount', 'closed_at']);
        $verifiedList = [];
        foreach ($verifiedCases as $c) {
            $k = $c->closed_at->format('Y-m');
            $cur = $c->currency ?: 'n/a';
            $months[$k]['verified_recovered'][$cur] = round(($months[$k]['verified_recovered'][$cur] ?? 0) + (float) $c->recovered_amount, 2);
            $verifiedList[] = ['case_uuid' => $c->uuid, 'title' => $c->title, 'month' => $k, 'currency' => $cur, 'recovered' => (float) $c->recovered_amount];
        }
        // Identified but not yet recovered (a snapshot, not tied to a month).
        $pending = LeakageCase::where('status', 'confirmed')->whereNotNull('exposure_amount')->get(['currency', 'exposure_amount'])->groupBy(fn ($c) => $c->currency ?: 'n/a')->map(fn ($g) => round((float) $g->sum('exposure_amount'), 2))->all();
        // Estimated: completed workflow runs x the written-down minutes per run. Completion month = last change of the instance.
        $estimates = [];
        foreach (ValueAssumption::all() as $a) {
            $def = Definition::find($a->definition_id);
            $runs = DB::table('workflow_instances')->where('tenant_id', $u->tenant_id)->where('definition_id', $a->definition_id)->where('status', 'completed')
                ->whereBetween('updated_at', [$start->startOfDay(), $end->endOfDay()])->pluck('updated_at');
            $byMonth = collect($runs)->groupBy(fn ($t) => CarbonImmutable::parse($t)->format('Y-m'))->map->count();
            $totalRuns = 0;
            foreach ($byMonth as $k => $n) {
                if (! isset($months[$k])) {
                    continue;
                }
                $hours = $n * $a->minutes_per_run / 60;
                $months[$k]['estimated_hours_saved'] = round($months[$k]['estimated_hours_saved'] + $hours, 2);
                if ($a->hourly_cost !== null) {
                    $months[$k]['estimated_value'][$a->currency] = round(($months[$k]['estimated_value'][$a->currency] ?? 0) + $hours * (float) $a->hourly_cost, 2);
                }
                $totalRuns += $n;
            }
            $estimates[] = ['definition_uuid' => $def?->uuid, 'name' => $def?->name, 'completed_runs' => $totalRuns, 'minutes_per_run' => $a->minutes_per_run, 'hours_saved' => round($totalRuns * $a->minutes_per_run / 60, 2), 'basis' => $a->basis];
        }
        $entries = ValueEntry::whereBetween('entry_month', [$start->toDateString(), $end->toDateString()])->orderBy('entry_month')->get();
        foreach ($entries as $e) {
            $k = $e->entry_month->format('Y-m');
            if ($e->amount !== null) {
                $months[$k]['claimed']['amount'][$e->currency] = round(($months[$k]['claimed']['amount'][$e->currency] ?? 0) + (float) $e->amount, 2);
            }
            $months[$k]['claimed']['hours'] = round($months[$k]['claimed']['hours'] + (float) ($e->hours ?? 0), 2);
            $months[$k]['claimed']['entries']++;
        }
        $sum = function (array $rows, string $field) {
            $t = [];
            foreach ($rows as $r) {
                foreach ($r[$field] as $cur => $v) {
                    $t[$cur] = round(($t[$cur] ?? 0) + $v, 2);
                }
            }

            return $t;
        };
        $monthRows = array_values($months);
        Audit::record('value.report', 'intelligence', 'user:'.$u->id, ['from' => $from, 'to' => $to]);

        return ['period' => ['from' => $from, 'to' => $to], 'months' => $monthRows,
            'totals' => [
                'verified_recovered' => $sum($monthRows, 'verified_recovered'),
                'identified_not_yet_recovered' => $pending,
                'estimated_hours_saved' => round(array_sum(array_column($monthRows, 'estimated_hours_saved')), 2),
                'estimated_value' => $sum($monthRows, 'estimated_value'),
                'claimed_amount' => $sum(array_map(fn ($r) => ['a' => $r['claimed']['amount']], $monthRows), 'a'),
                'claimed_hours' => round(array_sum(array_map(fn ($r) => $r['claimed']['hours'], $monthRows)), 2),
            ],
            'verified_cases' => $verifiedList, 'estimates' => $estimates, 'claims' => $entries->map(fn ($e) => $this->presentEntry($e))->all(),
            'confidence_note' => 'Verified means a reviewer marked a leakage case recovered with the amount that came back. Estimated is completed workflow runs times minutes you assumed. Claimed is a manual entry with its written basis. They are shown separately and never added together.',
            'limitations' => 'Estimates are only as good as the assumed minutes and hourly cost. Workflow completion month is the month the run last changed. Amounts in different currencies are not converted.'];
    }
}
