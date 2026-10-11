<?php

namespace App\Domain\Payroll\Components;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Contracts\FormulaEvaluator;
use App\Domain\Payroll\Models\{SalaryComponent};
use App\Domain\Payroll\Support\{Decimal, PayrollStates, Rounding};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Versioned salary components. A version is immutable once published or referenced; changes create a new version. */
final class ComponentService
{
    /** Variables available to every formula. Period variables are resolved by the run engine; compensation variables at assignment time. */
    public const COMPENSATION_VARIABLES = ['BASE', 'GROSS', 'ANNUAL', 'HOURLY_RATE', 'DAILY_RATE'];

    public const PERIOD_VARIABLES = ['WORKING_DAYS', 'CALENDAR_DAYS', 'PAYABLE_DAYS', 'PRESENT_DAYS', 'LOP_DAYS', 'OVERTIME_UNITS', 'PERIODS_PER_YEAR'];

    public const CALC_METHODS = ['fixed', 'percentage', 'formula', 'rate', 'input'];

    public const FIELDS = ['name', 'kind', 'currency', 'effective_from', 'effective_to', 'formula', 'payslip_visible', 'gl_code', 'nature', 'country', 'calc_method', 'tax_classification', 'proration',
        'attendance_dependent', 'lop_dependent', 'overtime_dependent', 'rounding_mode', 'rounding_scale', 'rounding_increment', 'min_amount', 'max_amount', 'statutory_mapping', 'active', 'sequence', 'config'];

    public function __construct(private FormulaEvaluator $formulas) {}

    /** Codes of other components, RULE_* outputs and the catalogue are legal formula variables. */
    public function allowedVariables(?string $ownCode = null): array
    {
        $codes = SalaryComponent::query()->distinct()->pluck('code')->all();

        return array_values(array_unique(array_merge(self::COMPENSATION_VARIABLES, self::PERIOD_VARIABLES, array_diff($codes, [$ownCode]))));
    }

    /** Formula references that are not compensation-time variables: the line is evaluated by the run engine instead. */
    public function isDeferred(string $formula): bool
    {
        return (bool) array_intersect($this->formulas->references($formula), array_merge(self::PERIOD_VARIABLES));
    }

    public function validateDefinition(array $d, ?string $ownCode = null): void
    {
        $e = [];
        if (! empty($d['formula'])) {
            $allowed = $this->allowedVariables($ownCode);
            foreach ($this->formulas->references($d['formula']) as $ref) if (str_starts_with($ref, 'RULE_')) $allowed[] = $ref;
            foreach ($this->formulas->validate($d['formula'], $allowed) as $problem) $e['formula'][] = $problem;
            if ($ownCode && in_array($ownCode, $this->formulas->references($d['formula']), true)) $e['formula'][] = 'A component cannot reference itself.';
            elseif ($ownCode && $this->createsCycle($ownCode, $d['formula'])) $e['formula'][] = 'This formula creates a circular dependency between components.';
        }
        if (($d['calc_method'] ?? 'fixed') === 'formula' && empty($d['formula'])) $e['formula'][] = 'A formula is required for calc method "formula".';
        if (($d['calc_method'] ?? 'fixed') === 'percentage') {
            $cfg = $d['config'] ?? [];
            if (empty($cfg['base_component']) || ! isset($cfg['percent']) || ! preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', (string) $cfg['percent'])) $e['config'][] = 'Percentage components need config.base_component and config.percent (decimal string).';
        }
        if (! empty($d['rounding_mode']) && ! Rounding::valid($d['rounding_mode'])) $e['rounding_mode'][] = 'Unknown rounding mode.';
        foreach (['min_amount', 'max_amount'] as $f) if (isset($d[$f]) && ! preg_match('/^-?\d{1,14}(?:\.\d{1,6})?$/D', (string) $d[$f])) $e[$f][] = 'Use a decimal string.';
        if (isset($d['min_amount'], $d['max_amount']) && Decimal::cmp((string) $d['min_amount'], (string) $d['max_amount']) > 0) $e['max_amount'][] = 'Maximum cannot be below minimum.';
        if ($e) throw ValidationException::withMessages($e);
    }

    private function createsCycle(string $code, string $formula): bool
    {
        $graph = SalaryComponent::query()->whereNotNull('formula')->get(['code', 'formula'])->mapWithKeys(fn ($c) => [$c->code => $this->formulas->references($c->formula)])->all();
        $graph[$code] = $this->formulas->references($formula);
        $seen = [];
        $walk = function (string $n) use (&$walk, &$graph, $code, &$seen) {
            foreach ($graph[$n] ?? [] as $dep) { if ($dep === $code) return true; if (isset($seen[$dep]) || ! isset($graph[$dep])) continue; $seen[$dep] = true; if ($walk($dep)) return true; }
            return false;
        };

        return $walk($code);
    }

    /** Evaluation order for component codes: dependencies first (Kahn), `sequence` as tie-break. */
    public function evaluationOrder(array $components): array
    {
        $byCode = collect($components)->keyBy('code'); $deps = [];
        foreach ($byCode as $code => $c) $deps[$code] = array_values(array_intersect($this->formulas->references((string) ($c->formula ?? '')), $byCode->keys()->all()));
        $order = [];
        while ($deps) {
            $ready = array_keys(array_filter($deps, fn ($d) => ! array_diff($d, $order)));
            if (! $ready) throw ValidationException::withMessages(['components' => 'Circular component dependency: '.implode(', ', array_keys($deps))]);
            usort($ready, fn ($a, $b) => [$byCode[$a]->sequence, $a] <=> [$byCode[$b]->sequence, $b]);
            foreach ($ready as $r) { $order[] = $r; unset($deps[$r]); }
        }

        return $order;
    }

    public function create(array $d, int $actorId): SalaryComponent
    {
        $this->validateDefinition($d, $d['code']);
        $d['created_by'] = $actorId;
        $d += ['calc_method' => ! empty($d['formula']) ? 'formula' : 'fixed'];

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', auth()->user()->tenant_id)->lockForUpdate()->first();
            if (SalaryComponent::where('code', $d['code'])->where('effective_from', '<=', $d['effective_to'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $d['effective_from']))->exists())
                throw ValidationException::withMessages(['effective_from' => 'Component version effective dates overlap.']);
            $c = SalaryComponent::create($d + ['version' => (SalaryComponent::where('code', $d['code'])->max('version') ?? 0) + 1]);
            Audit::record('component.created', 'payroll', $c->uuid);

            return $c;
        });
    }

    /** New version of a code: copies the latest version, applies changes and closes the previous open version the day before. */
    public function newVersion(SalaryComponent $base, array $changes, int $actorId): SalaryComponent
    {
        return DB::transaction(function () use ($base, $changes, $actorId) {
            DB::table('tenants')->where('id', auth()->user()->tenant_id)->lockForUpdate()->first();
            $latest = SalaryComponent::where('code', $base->code)->orderByDesc('version')->firstOrFail();
            $from = $changes['effective_from'] ?? throw ValidationException::withMessages(['effective_from' => 'Required.']);
            if (substr((string) $latest->effective_from, 0, 10) >= $from) throw ValidationException::withMessages(['effective_from' => 'A new version must start after the latest version ('.substr((string) $latest->effective_from, 0, 10).').']);
            $data = collect($latest->only(self::FIELDS))->merge(array_intersect_key($changes, array_flip(self::FIELDS)))->all();
            $data['effective_to'] = $changes['effective_to'] ?? null;
            $this->validateDefinition($data + ['code' => $base->code], $base->code);
            if ($latest->effective_to === null || substr((string) $latest->effective_to, 0, 10) >= $from) {
                $latest->forceFill(['effective_to' => \Carbon\CarbonImmutable::parse($from)->subDay()->toDateString()])->save();
            }
            $c = SalaryComponent::create($data + ['code' => $base->code, 'version' => $latest->version + 1, 'created_by' => $actorId]);
            Audit::record('component.version_created', 'payroll', $c->uuid, ['code' => $c->code, 'version' => $c->version]);

            return $c;
        });
    }

    public function isUsed(SalaryComponent $c): bool
    {
        return $c->published_at !== null || DB::table('compensation_lines')->where('component_id', $c->id)->exists() || DB::table('salary_structure_components')->where('component_id', $c->id)->exists();
    }

    public function update(SalaryComponent $c, array $changes): SalaryComponent
    {
        if ($this->isUsed($c)) throw ValidationException::withMessages(['component' => 'This version is published or in use and cannot be edited. Create a new version instead.']);
        $data = collect($c->only(self::FIELDS))->merge(array_intersect_key($changes, array_flip(self::FIELDS)))->all();
        $this->validateDefinition($data + ['code' => $c->code], $c->code);
        $c->update(array_intersect_key($changes, array_flip(self::FIELDS)));
        Audit::record('component.updated', 'payroll', $c->uuid);

        return $c;
    }

    public function publish(SalaryComponent $c): SalaryComponent
    {
        if (! $c->published_at) { $c->update(['published_at' => now()]); Audit::record('component.published', 'payroll', $c->uuid); }

        return $c;
    }

    public function present(SalaryComponent $c): array
    {
        return $c->only(['uuid', 'code', 'name', 'kind', 'currency', 'version', 'effective_from', 'effective_to', 'formula', 'payslip_visible', 'gl_code', 'nature', 'country', 'calc_method',
            'tax_classification', 'proration', 'attendance_dependent', 'lop_dependent', 'overtime_dependent', 'rounding_mode', 'rounding_scale', 'rounding_increment', 'min_amount', 'max_amount',
            'statutory_mapping', 'active', 'sequence', 'config']) + ['published' => $c->published_at !== null, 'in_use' => $this->isUsed($c)];
    }

    public static function kinds(): array { return PayrollStates::COMPONENT_KINDS; }
}
