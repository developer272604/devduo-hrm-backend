<?php

namespace App\Domain\Payroll\Compensation;

use App\Domain\Payroll\Calendars\PeriodGenerator;
use App\Domain\Payroll\Components\ComponentService;
use App\Domain\Payroll\Contracts\FormulaEvaluator;
use App\Domain\Payroll\Models\{PayrollGroup, SalaryComponent, SalaryStructure};
use App\Domain\Payroll\Support\{Decimal, Money, PayrollSettings, Rounding};
use Illuminate\Validation\ValidationException;

/**
 * Turns requested lines (component + optional amount) into stored lines: validates currency/coverage, evaluates percentage and formula
 * components in dependency order, applies min/max and rounding, and derives period / annual / daily / hourly figures. Decimal strings only.
 *
 * Compensation-time variables: BASE (line named by structure.rules.base_component, default BASE), GROSS (sum of non-formula earnings),
 * ANNUAL (GROSS x periods per year), DAILY_RATE, HOURLY_RATE and the amount of every already resolved component code. A formula that uses
 * a period variable (PAYABLE_DAYS, ...) is DEFERRED: stored with amount 0 and basis.deferred = true; the run engine evaluates it.
 */
final class CompensationCalculator
{
    public function __construct(private FormulaEvaluator $formulas, private ComponentService $components) {}

    public static function periodsPerYear(PayrollGroup $group): int
    {
        return PeriodGenerator::PER_YEAR[$group->frequency] ?? (int) ($group->settings['periods_per_year'] ?? 12);
    }

    /**
     * @param  array<int,array{component_uuid:string,amount?:?string}>  $input
     * @return array{lines:array<int,array<string,mixed>>,summary:array<string,?string>}
     */
    public function build(array $input, PayrollGroup $group, string $from, ?string $to, ?SalaryStructure $structure = null, array $overrides = []): array
    {
        $comps = SalaryComponent::whereIn('uuid', array_column($input, 'component_uuid'))->get()->keyBy('uuid');
        $exp = Money::exponent($group->currency);
        $items = [];
        foreach ($input as $i => $line) {
            $c = $comps->get($line['component_uuid']);
            if (! $c || $c->currency !== $group->currency || substr((string) $c->effective_from, 0, 10) > $from || ($c->effective_to && substr((string) $c->effective_to, 0, 10) < ($to ?? '9999-12-31')))
                $this->invalid('lines', 'Each component must belong to this workspace, match the group currency and cover the compensation period.');
            if (! $c->active) $this->invalid('lines', 'Component '.$c->code.' is inactive.');
            $items[$c->code] = ['component' => $c, 'amount' => $line['amount'] ?? null, 'index' => $i];
        }
        if (count($items) !== count($input)) $this->invalid('lines', 'A component can appear only once.');

        if ($structure) {
            $inStructure = $structure->components()->get()->keyBy('component_code');
            foreach ($items as $code => $_) if (! $inStructure->has($code)) $this->invalid('lines', 'Component '.$code.' is not part of structure '.$structure->code.'.');
            foreach ($inStructure as $code => $sc) {
                if (isset($items[$code])) { $items[$code]['sequence'] = $sc->sequence; continue; }
                $c = SalaryComponent::where('code', $code)->where('effective_from', '<=', $from)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->orderByDesc('version')->first();
                $computed = $c && in_array($c->calc_method, ['formula', 'percentage'], true);
                if ($sc->required && ! $computed) $this->invalid('lines', 'Structure '.$structure->code.' requires component '.$code.'.');
                if ($c && $computed) $items[$code] = ['component' => $c, 'amount' => null, 'index' => 999, 'sequence' => $sc->sequence];
            }
        }

        $order = $this->components->evaluationOrder(array_map(fn ($x) => $x['component'], array_values($items)));
        $baseCode = $structure?->rules['base_component'] ?? 'BASE';
        $fixedGross = '0';
        foreach ($items as $code => $it) if ($it['component']->kind === 'earning' && ! in_array($it['component']->calc_method, ['formula', 'percentage'], true) && $it['amount'] !== null) $fixedGross = (string) Decimal::add($fixedGross, $it['amount']);
        $ppy = self::periodsPerYear($group);
        $annual = Rounding::apply(Decimal::mul($fixedGross, $ppy), 6);
        $daysPerYear = $group->settings['days_per_year'] ?? PayrollSettings::get('calculation.days_per_year', '260');
        $hoursPerDay = $group->settings['hours_per_day'] ?? PayrollSettings::get('calculation.hours_per_day', '8');
        $daily = Decimal::isZero($annual) ? '0' : (string) Decimal::div($annual, $daysPerYear, 6);
        $hourly = Decimal::isZero($daily) ? '0' : (string) Decimal::div($daily, $hoursPerDay, 6);
        $vars = ['GROSS' => (string) Decimal::of($fixedGross)->toScale(6), 'ANNUAL' => $annual, 'DAILY_RATE' => $daily, 'HOURLY_RATE' => $hourly, 'PERIODS_PER_YEAR' => (string) $ppy] + array_map(fn ($v) => $v, $overrides);
        foreach ($items as $code => $it) if ($it['amount'] !== null && ! in_array($it['component']->calc_method, ['formula', 'percentage'], true)) { $this->guard($it['amount']); $vars[$code] = (string) Decimal::of($it['amount']); }
        if (isset($vars[$baseCode])) $vars['BASE'] = $vars[$baseCode];

        $lines = [];
        foreach ($order as $code) {
            $it = $items[$code]; $c = $it['component']; $basis = ['type' => $c->calc_method, 'component_version' => $c->version];
            switch ($c->calc_method) {
                case 'formula':
                    if ($this->components->isDeferred($c->formula)) { $amount = '0'; $basis += ['deferred' => true, 'formula' => $c->formula]; break; }
                    try { $r = $this->formulas->evaluate($c->formula, $vars, 6); } catch (\InvalidArgumentException $e) { $this->invalid('lines', $c->code.': '.$e->getMessage()); }
                    $amount = $r->value; $basis += ['formula' => $c->formula, 'trace' => $r->trace()];
                    break;
                case 'percentage':
                    $b = $c->config['base_component'] ?? 'BASE';
                    if (! isset($vars[$b])) $this->invalid('lines', $c->code.' needs component '.$b.' to compute its percentage.');
                    $amount = (string) Decimal::mul($vars[$b], Decimal::div($c->config['percent'], 100, 8)); $basis += ['base_component' => $b, 'percent' => $c->config['percent'], 'base_amount' => $vars[$b]];
                    break;
                default:
                    if ($it['amount'] === null) { if ($c->calc_method === 'input') { $amount = '0'; $basis['deferred'] = true; break; } $this->invalid('lines', 'An amount is required for component '.$c->code.'.'); }
                    $amount = (string) Decimal::of($it['amount']);
            }
            if (! ($basis['deferred'] ?? false)) {
                if ($c->min_amount !== null && Decimal::cmp($amount, $c->min_amount) < 0) { $amount = $c->min_amount; $basis['clamped'] = 'min'; }
                if ($c->max_amount !== null && Decimal::cmp($amount, $c->max_amount) > 0) { $amount = $c->max_amount; $basis['clamped'] = 'max'; }
            }
            $amount = Rounding::apply($amount, $c->rounding_scale ?? $exp, $c->rounding_mode ?? Rounding::HALF_UP, $c->rounding_increment);
            if (Decimal::isNegative($amount)) $this->invalid('lines', 'Component '.$c->code.' resolves to a negative amount.');
            $amount = Decimal::str($amount, $exp);
            $vars[$code] = $amount; if ($code === $baseCode) $vars['BASE'] = $amount;
            $lines[] = ['component' => $c, 'amount' => $amount, 'basis' => $basis, 'sequence' => $it['sequence'] ?? (($it['index'] ?? 0) + 1) * 10];
        }
        usort($lines, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        $gross = '0';
        foreach ($lines as $l) if ($l['component']->kind === 'earning' && ! ($l['basis']['deferred'] ?? false)) $gross = (string) Decimal::add($gross, $l['amount']);
        $annualFinal = Rounding::apply(Decimal::mul($gross, $ppy), $exp);
        $dailyFinal = Decimal::isZero($annualFinal) ? '0' : Rounding::apply(Decimal::div($annualFinal, $daysPerYear, 6), 4);
        $summary = ['base_pay' => $vars['BASE'] ?? null, 'gross_reference' => Decimal::str($gross, $exp), 'annual_compensation' => Decimal::str($annualFinal, $exp),
            'daily_rate' => Decimal::str($dailyFinal, 4), 'hourly_rate' => Decimal::isZero($dailyFinal) ? '0.0000' : Decimal::str(Rounding::apply(Decimal::div($dailyFinal, $hoursPerDay, 6), 4), 4)];

        return ['lines' => $lines, 'summary' => $summary];
    }

    private function guard(mixed $amount): void
    {
        if (! is_string($amount) || ! preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $amount)) $this->invalid('lines', 'Amounts must be non-negative decimal strings.');
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
