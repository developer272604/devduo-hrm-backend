<?php
namespace App\Domain\Assets;

use App\Domain\Assets\Models\{Asset, AssetMaintenance, AssetProcurement};
use App\Domain\Audit\Audit;
use App\Domain\Operations\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Append-only maintenance and procurement/warranty history, plus straight-line depreciation. History is corrected by adding records, never by editing them. */
final class AssetHistoryService {
    public function __construct(private AssetService $assets) {}
    private function asset(User $user, string $uuid): Asset { abort_unless($this->assets->manages($user), 403); return Asset::where('uuid', $uuid)->firstOrFail(); }

    public function history(User $user, string $uuid): array {
        $a = $this->asset($user, $uuid);
        $m = fn ($v, $s) => $v === null ? null : Money::format((int) $v, (int) $s);
        return [
            'maintenance' => AssetMaintenance::where('asset_id', $a->id)->orderByDesc('started_on')->orderByDesc('id')->get()->map(fn ($r) => ['uuid' => $r->uuid, 'kind' => $r->kind, 'vendor' => $r->vendor, 'currency' => $r->currency,
                'cost' => $r->currency ? $m($r->cost_minor, $r->minor_units) : null, 'started_on' => $r->started_on->toDateString(), 'completed_on' => $r->completed_on?->toDateString(), 'notes' => $r->notes])->values(),
            'procurement' => AssetProcurement::where('asset_id', $a->id)->orderByDesc('id')->get()->map(fn ($r) => ['uuid' => $r->uuid, 'kind' => $r->kind, 'vendor' => $r->vendor, 'reference' => $r->reference, 'purchased_on' => $r->purchased_on?->toDateString(),
                'currency' => $r->currency, 'cost' => $m($r->cost_minor, $r->minor_units), 'salvage' => $r->cost_minor === null ? null : $m($r->salvage_minor, $r->minor_units), 'useful_life_months' => $r->useful_life_months,
                'warranty_until' => $r->warranty_until?->toDateString(), 'notes' => $r->notes])->values(),
        ];
    }
    public function addMaintenance(User $user, string $uuid, array $d): AssetMaintenance {
        $a = $this->asset($user, $uuid); $scale = 2; $cost = 0;
        if (! empty($d['cost'])) { $scale = Money::scale($d['currency'] ?? ''); $cost = Money::minor($d['cost'], $scale, 'cost'); }
        if (! empty($d['completed_on']) && $d['completed_on'] < $d['started_on']) throw ValidationException::withMessages(['completed_on' => 'Completion cannot be before the start date.']);
        return DB::transaction(function () use ($user, $a, $d, $scale, $cost) {
            $r = AssetMaintenance::create(['asset_id' => $a->id, 'kind' => $d['kind'], 'vendor' => $d['vendor'] ?? null, 'currency' => $cost ? $d['currency'] : null, 'minor_units' => $scale, 'cost_minor' => $cost,
                'started_on' => $d['started_on'], 'completed_on' => $d['completed_on'] ?? null, 'notes' => $d['notes'] ?? null, 'created_by' => $user->id]);
            Audit::record('asset.maintenance_recorded', 'assets', 'asset:'.$a->uuid);
            return $r;
        });
    }
    public function addProcurement(User $user, string $uuid, array $d): AssetProcurement {
        $a = $this->asset($user, $uuid); $scale = 2; $cost = null; $salvage = 0;
        if (isset($d['cost'])) { $scale = Money::scale($d['currency'] ?? ''); $cost = Money::minor($d['cost'], $scale, 'cost'); $salvage = isset($d['salvage']) ? Money::minor($d['salvage'], $scale, 'salvage') : 0; if ($salvage > $cost) throw ValidationException::withMessages(['salvage' => 'Salvage value cannot exceed cost.']); }
        if ($d['kind'] === 'purchase' && ($cost === null || empty($d['purchased_on']))) throw ValidationException::withMessages(['cost' => 'A purchase record needs a cost and purchase date.']);
        return DB::transaction(function () use ($user, $a, $d, $scale, $cost, $salvage) {
            $r = AssetProcurement::create(['asset_id' => $a->id, 'kind' => $d['kind'], 'vendor' => $d['vendor'] ?? null, 'reference' => $d['reference'] ?? null, 'purchased_on' => $d['purchased_on'] ?? null, 'currency' => $cost === null ? null : $d['currency'],
                'minor_units' => $scale, 'cost_minor' => $cost, 'salvage_minor' => $salvage, 'useful_life_months' => $d['useful_life_months'] ?? null, 'warranty_until' => $d['warranty_until'] ?? null, 'notes' => $d['notes'] ?? null, 'created_by' => $user->id]);
            if (! empty($d['warranty_until']) && (! $a->warranty_until || $d['warranty_until'] > $a->warranty_until->toDateString())) { $a->warranty_until = $d['warranty_until']; $a->save(); }
            Audit::record('asset.procurement_recorded', 'assets', 'asset:'.$a->uuid);
            return $r;
        });
    }

    /** Whole months between two Y-m-d dates (a month counts once its day-of-month has been reached). */
    public static function wholeMonths(string $from, string $to): int {
        [$y1, $m1, $d1] = array_map('intval', explode('-', $from)); [$y2, $m2, $d2] = array_map('intval', explode('-', $to));
        return max(0, ($y2 - $y1) * 12 + ($m2 - $m1) - ($d2 < $d1 ? 1 : 0));
    }
    /** Straight-line: accumulated = (cost - salvage) x months / life, rounded half-up in minor units. All integer maths. */
    public static function straightLine(int $cost, int $salvage, int $life, int $months): array {
        $months = min($months, $life); $depreciable = max(0, $cost - $salvage);
        $accumulated = intdiv(2 * $depreciable * $months + $life, 2 * $life);
        return ['accumulated' => $accumulated, 'book_value' => $cost - $accumulated, 'months' => $months];
    }
    public function depreciation(User $user, string $asOf): array {
        abort_unless($this->assets->manages($user), 403);
        $rows = []; $totals = [];
        foreach (Asset::orderBy('identifier')->get() as $a) {
            $p = AssetProcurement::where('asset_id', $a->id)->where('kind', 'purchase')->whereNotNull('cost_minor')->whereNotNull('useful_life_months')->whereNotNull('purchased_on')->where('purchased_on', '<=', $asOf)->orderByDesc('purchased_on')->orderByDesc('id')->first();
            if (! $p) continue;
            $r = self::straightLine($p->cost_minor, $p->salvage_minor, $p->useful_life_months, self::wholeMonths($p->purchased_on->toDateString(), $asOf)); $f = fn ($v) => Money::format($v, $p->minor_units);
            $rows[] = ['asset_uuid' => $a->uuid, 'identifier' => $a->identifier, 'name' => $a->name, 'category' => $a->category, 'status' => $a->status, 'currency' => $p->currency, 'cost' => $f($p->cost_minor), 'salvage' => $f($p->salvage_minor),
                'useful_life_months' => $p->useful_life_months, 'months_elapsed' => $r['months'], 'accumulated' => $f($r['accumulated']), 'book_value' => $f($r['book_value']), 'fully_depreciated' => $r['months'] >= $p->useful_life_months, 'method' => 'straight_line'];
            $t = &$totals[$p->currency]; $t['cost'] = ($t['cost'] ?? 0) + $p->cost_minor; $t['accumulated'] = ($t['accumulated'] ?? 0) + $r['accumulated']; $t['book_value'] = ($t['book_value'] ?? 0) + $r['book_value']; $t['scale'] = $p->minor_units; unset($t);
        }
        $out = []; foreach ($totals as $cur => $t) $out[] = ['currency' => $cur, 'cost' => Money::format($t['cost'], $t['scale']), 'accumulated' => Money::format($t['accumulated'], $t['scale']), 'book_value' => Money::format($t['book_value'], $t['scale'])];
        return ['as_of' => $asOf, 'method' => 'straight_line', 'note' => 'Management information only; not an accounting or tax depreciation schedule.', 'rows' => $rows, 'totals' => $out];
    }
}
