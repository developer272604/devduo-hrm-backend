<?php
namespace App\Domain\Expenses;

use App\Domain\Audit\Audit;
use App\Domain\Expenses\Models\{ExpenseLimitRule, ExpenseRateTable};
use App\Domain\Operations\{Money, RequestAccess};
use App\Domain\Payroll\Support\Decimal;
use App\Models\User;
use Brick\Math\{BigDecimal, RoundingMode};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Effective-dated, versioned limit rules and mileage / per-diem rate tables. A change is always a NEW version; history is never rewritten. */
final class ExpenseRuleService {
    public function __construct(private RequestAccess $access) {}

    private function dimensions(array $d): void {
        foreach (['legal_entity_id' => 'legal_entities', 'grade_id' => 'grades'] as $key => $table) {
            if (! empty($d[$key]) && ! DB::table($table)->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('id', $d[$key])->exists()) throw ValidationException::withMessages([$key => 'Choose a record from this workspace.']);
        }
    }
    public function limitView(ExpenseLimitRule $r): array {
        $f = fn ($v) => $v === null ? null : Money::format((int) $v, $r->minor_units);
        return ['uuid' => $r->uuid, 'rule_key' => $r->rule_key, 'version' => $r->version, 'name' => $r->name, 'category' => $r->category, 'country' => $r->country, 'legal_entity_id' => $r->legal_entity_id, 'grade_id' => $r->grade_id,
            'currency' => $r->currency, 'per_line_limit' => $f($r->per_line_limit_minor), 'category_total_limit' => $f($r->category_total_limit_minor), 'receipt_over' => $f($r->receipt_over_minor), 'receipt_always' => $r->receipt_always,
            'action' => $r->action, 'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString()];
    }
    public function rateView(ExpenseRateTable $r): array {
        return ['uuid' => $r->uuid, 'rate_key' => $r->rate_key, 'version' => $r->version, 'kind' => $r->kind, 'name' => $r->name, 'country' => $r->country, 'grade_id' => $r->grade_id, 'currency' => $r->currency,
            'unit' => $r->unit, 'rate' => Decimal::fromDb($r->getRawOriginal('rate'), 6), 'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString()];
    }
    public function createLimit(User $user, array $d): ExpenseLimitRule {
        $this->access->tenantManager($user, 'expenses.manage');
        $prev = isset($d['rule_key']) ? ExpenseLimitRule::where('rule_key', $d['rule_key'])->orderByDesc('version')->firstOrFail() : null;
        if ($prev) $d = array_merge(['name' => $prev->name, 'category' => $prev->category, 'country' => $prev->country, 'legal_entity_id' => $prev->legal_entity_id, 'grade_id' => $prev->grade_id, 'currency' => $prev->currency], array_diff_key($d, array_flip(['name', 'category', 'country', 'legal_entity_id', 'grade_id', 'currency'])));
        $this->dimensions($d); $scale = Money::scale($d['currency']);
        $m = fn ($k) => isset($d[$k]) && $d[$k] !== '' ? Money::minor($d[$k], $scale, $k) : null;
        $line = $m('per_line_limit'); $total = $m('category_total_limit'); $over = $m('receipt_over'); $always = (bool) ($d['receipt_always'] ?? false);
        if ($line === null && $total === null && $over === null && ! $always) throw ValidationException::withMessages(['rule' => 'Set a limit or a receipt rule.']);
        if ($prev && $d['effective_from'] < $prev->effective_from->toDateString()) throw ValidationException::withMessages(['effective_from' => 'A new version cannot start before the previous version.']);
        return DB::transaction(function () use ($user, $d, $prev, $scale, $line, $total, $over, $always) {
            $r = ExpenseLimitRule::create(['rule_key' => $prev?->rule_key ?? (string) Str::uuid(), 'version' => ($prev?->version ?? 0) + 1, 'name' => $d['name'], 'category' => $d['category'], 'country' => $d['country'] ?? null,
                'legal_entity_id' => $d['legal_entity_id'] ?? null, 'grade_id' => $d['grade_id'] ?? null, 'currency' => $d['currency'], 'minor_units' => $scale, 'per_line_limit_minor' => $line, 'category_total_limit_minor' => $total,
                'receipt_over_minor' => $over, 'receipt_always' => $always, 'action' => $d['action'], 'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'] ?? null, 'created_by' => $user->id]);
            Audit::record('expense.limit_rule_created', 'expenses', 'rule:'.$r->uuid);
            return $r;
        });
    }
    public function createRate(User $user, array $d): ExpenseRateTable {
        $this->access->tenantManager($user, 'expenses.manage');
        $prev = isset($d['rate_key']) ? ExpenseRateTable::where('rate_key', $d['rate_key'])->orderByDesc('version')->firstOrFail() : null;
        if ($prev) $d = array_merge(['kind' => $prev->kind, 'name' => $prev->name, 'country' => $prev->country, 'grade_id' => $prev->grade_id, 'currency' => $prev->currency, 'unit' => $prev->unit], array_diff_key($d, array_flip(['kind', 'name', 'country', 'grade_id', 'currency', 'unit'])));
        $this->dimensions($d); $scale = Money::scale($d['currency']);
        if (! preg_match('/^\d{1,9}(?:\.\d{1,6})?$/', $d['rate']) || BigDecimal::of($d['rate'])->isZero()) throw ValidationException::withMessages(['rate' => 'Enter a positive rate with at most 6 decimal places.']);
        if (! in_array($d['unit'], $d['kind'] === 'mileage' ? ['km', 'mile'] : ['day'], true)) throw ValidationException::withMessages(['unit' => 'Mileage uses km or mile; per diem uses day.']);
        if ($prev && $d['effective_from'] < $prev->effective_from->toDateString()) throw ValidationException::withMessages(['effective_from' => 'A new version cannot start before the previous version.']);
        return DB::transaction(function () use ($user, $d, $prev, $scale) {
            $r = ExpenseRateTable::create(['rate_key' => $prev?->rate_key ?? (string) Str::uuid(), 'version' => ($prev?->version ?? 0) + 1, 'kind' => $d['kind'], 'name' => $d['name'], 'country' => $d['country'] ?? null, 'grade_id' => $d['grade_id'] ?? null,
                'currency' => $d['currency'], 'minor_units' => $scale, 'unit' => $d['unit'], 'rate' => BigDecimal::of($d['rate'])->toScale(6)->__toString(), 'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'] ?? null, 'created_by' => $user->id]);
            Audit::record('expense.rate_table_created', 'expenses', 'rate:'.$r->uuid);
            return $r;
        });
    }
    /** Latest effective version of each rule key, optionally as of a date. */
    public function latest(string $model, ?string $date = null) {
        $keyColumn = $model === ExpenseLimitRule::class ? 'rule_key' : 'rate_key';
        $q = $model::query(); if ($date) $q->where('effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
        return $q->orderByDesc('version')->get()->unique($keyColumn)->values();
    }
    private function specificity($r, array $dims): ?int {
        $score = 0;
        foreach ($dims as $column => $value) { if ($r->$column === null) continue; if ((string) $r->$column !== (string) $value) return null; $score++; }
        return $score;
    }
    /** @return ExpenseLimitRule|null the most specific rule matching category and the employee's country / entity / grade on the date */
    public function limitFor(string $category, string $date, string $currency, array $ctx): ?ExpenseLimitRule {
        $best = null; $bestScore = -1;
        foreach ($this->latest(ExpenseLimitRule::class, $date) as $r) {
            if ($r->currency !== $currency || mb_strtolower($r->category) !== mb_strtolower($category)) continue;
            $score = $this->specificity($r, ['country' => $ctx['country'] ?? null, 'legal_entity_id' => $ctx['legal_entity_id'] ?? null, 'grade_id' => $ctx['grade_id'] ?? null]);
            if ($score !== null && $score > $bestScore) { $best = $r; $bestScore = $score; }
        }
        return $best;
    }
    public function rateFor(string $kind, string $date, string $currency, array $ctx): ?ExpenseRateTable {
        $best = null; $bestScore = -1;
        foreach ($this->latest(ExpenseRateTable::class, $date) as $r) {
            if ($r->kind !== $kind || $r->currency !== $currency) continue;
            $score = $this->specificity($r, ['country' => $ctx['country'] ?? null, 'grade_id' => $ctx['grade_id'] ?? null]);
            if ($score !== null && $score > $bestScore) { $best = $r; $bestScore = $score; }
        }
        return $best;
    }
    /** quantity x rate, rounded half-up to the currency's minor units. */
    public static function amountFor(string $quantity, string $rate, int $scale): int {
        return (int) BigDecimal::of($quantity)->multipliedBy($rate)->multipliedBy(BigDecimal::of(10)->power($scale))->toScale(0, RoundingMode::HALF_UP)->__toString();
    }
}
