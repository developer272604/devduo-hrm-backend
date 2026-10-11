<?php

namespace App\Domain\Payroll\CountryPacks;

use App\Domain\Payroll\Contracts\Dto\{StatutoryContext, StatutoryResult};
use App\Domain\Payroll\Contracts\{FormulaEvaluator, StatutoryPack};
use App\Domain\Payroll\Support\{Decimal, Money, Rounding};

/**
 * Generic interpreter of declarative pack rules (see RuleValidator for the schema). Pure: same context => same result. No country is known here;
 * all rates, slabs and caps come from the published pack version in the context.
 */
final class DeclarativePack implements StatutoryPack
{
    public const HANDLER = 'declarative';

    public function __construct(private FormulaEvaluator $formulas) {}

    public function handler(): string { return self::HANDLER; }

    public function calculate(StatutoryContext $ctx): StatutoryResult
    {
        $lines = []; $warnings = []; $errors = [];
        $exp = Money::exponent($ctx->currency);
        foreach ($ctx->rules['concepts'] ?? [] as $c) {
            try {
                if (! $this->applies($c, $ctx->amounts)) continue;
                $baseKey = $c['base'] ?? 'gross';
                if (! array_key_exists($baseKey, $ctx->amounts) && ($c['type'] ?? '') !== 'flat') { $warnings[] = ['code' => 'base_missing', 'rule_id' => $c['id'], 'message' => 'Base "'.$baseKey.'" is not available; rule skipped.']; continue; }
                $base = (string) Decimal::of($ctx->amounts[$baseKey] ?? '0');
                $trace = ['rule_id' => $c['id'], 'type' => $c['type'], 'pack_version' => $ctx->packVersion, 'base_key' => $baseKey, 'base' => $base];
                if (isset($c['wage_floor']) && Decimal::cmp($base, $c['wage_floor']) < 0) continue;
                if (isset($c['wage_cap']) && Decimal::cmp($base, $c['wage_cap']) > 0) { $base = $c['wage_cap']; $trace['capped_base'] = $base; }
                $raw = match ($c['type']) {
                    'rate' => (string) Decimal::mul($base, Decimal::div($c['rate'], 100, 10)),
                    'flat' => (string) Decimal::of($c['amount']),
                    'slab' => $this->slab($c, $base, $ctx->periodsInYear, $trace),
                    'formula' => $this->formulas->evaluate($c['formula'], $this->vars($ctx->amounts), 6)->value,
                };
                if (isset($c['min']) && Decimal::cmp($raw, $c['min']) < 0) $raw = $c['min'];
                if (isset($c['max']) && Decimal::cmp($raw, $c['max']) > 0) $raw = $c['max'];
                $amount = Rounding::apply($raw, $c['rounding']['scale'] ?? $exp, $c['rounding']['mode'] ?? Rounding::HALF_UP);
                if (Decimal::isNegative($amount)) $amount = '0';
                $lines[] = ['concept' => $c['id'], 'kind' => $c['kind'], 'component_code' => $c['component_code'] ?? null, 'amount' => Decimal::str($amount, $exp), 'taxable_base' => $base, 'rule_id' => $c['id'], 'trace' => $trace];
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['code' => 'rule_failed', 'rule_id' => $c['id'] ?? '?', 'message' => $e->getMessage()];
            }
        }

        return new StatutoryResult($lines, $warnings, $errors);
    }

    /** Progressive annual slabs; annualise the period base, tax it, divide back by the periods per year. */
    private function slab(array $c, string $base, int $ppy, array &$trace): string
    {
        $annualize = $c['annualize'] ?? true;
        $annual = $annualize ? (string) Decimal::mul($base, $ppy) : $base;
        $tax = '0'; $lower = '0'; $parts = [];
        foreach ($c['slabs'] as $s) {
            $upper = $s['up_to'];
            $portion = $upper === null ? Decimal::sub($annual, $lower) : Decimal::sub(Decimal::min($annual, $upper), $lower);
            if (! $portion->isPositive()) break;
            $part = Decimal::mul($portion, Decimal::div($s['rate'], 100, 10));
            $tax = (string) Decimal::add($tax, $part); $parts[] = ['from' => $lower, 'to' => $upper, 'rate' => $s['rate'], 'portion' => (string) $portion];
            if ($upper === null) break;
            $lower = $upper;
        }
        $trace += ['annual_base' => $annual, 'annual_tax' => $tax, 'periods_in_year' => $ppy, 'slabs_used' => $parts];

        return $annualize ? (string) Decimal::div($tax, $ppy, 10) : $tax;
    }

    private function applies(array $c, array $amounts): bool
    {
        foreach ($c['when'] ?? [] as $w) {
            $v = $amounts[$w['base']] ?? null;
            if ($v === null) return false;
            $cmp = Decimal::cmp($v, $w['value']);
            if (! match ($w['op']) { 'gte' => $cmp >= 0, 'gt' => $cmp > 0, 'lte' => $cmp <= 0, 'lt' => $cmp < 0, 'eq' => $cmp === 0 }) return false;
        }

        return true;
    }

    /** Pack formulas use lowercase amount keys; the grammar is uppercase, so expose UPPER_CASE aliases. */
    private function vars(array $amounts): array
    {
        $out = [];
        foreach ($amounts as $k => $v) $out[strtoupper($k)] = (string) $v;

        return $out;
    }

    public function validate(StatutoryContext $ctx): array
    {
        $issues = [];
        foreach ($ctx->validationRules['required_identifiers'] ?? [] as $key) {
            if (empty($ctx->employee['identifiers'][$key] ?? null)) $issues[] = ['code' => 'identifier_missing', 'severity' => 'blocking', 'field' => $key, 'message' => 'Statutory identifier "'.$key.'" is missing for this employee.'];
        }
        if ($ctx->rules && ($ctx->rules['non_authoritative'] ?? false)) $issues[] = ['code' => 'pack_not_authoritative', 'severity' => 'warning', 'message' => 'This country pack is an illustrative sample and has not been validated by an expert.'];

        return $issues;
    }

    public function identifiers(array $rules): array { return $rules['identifiers'] ?? []; }

    public function reports(array $rules): array { return array_values(array_unique(array_map(fn ($c) => $c['id'], $rules['concepts'] ?? []))); }
}
