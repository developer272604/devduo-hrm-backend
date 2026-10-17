<?php

namespace App\Domain\Analytics\Impact;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Audit\Audit;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Business Impact Intelligence: company-entered business results read against workforce cost and headcount.
 * Cost and headcount come from the existing Analytics metrics (payroll_totals, headcount), so the viewer's payroll permission, data scope and
 * small-cohort suppression apply unchanged: someone without payroll access sees the results but never the cost. Ratios are arithmetic
 * over those figures. They show how cost and output move together; they do not prove one caused the other.
 */
final class ImpactService
{
    public const KINDS = ['output', 'revenue'];

    private const MAX_MONTHS = 24;

    private const MAX_IMPORT_ROWS = 500;

    public function __construct(private AnalyticsService $analytics) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    // ---- definitions -------------------------------------------------------------------------------------------------------------

    public function metrics(): array
    {
        return ImpactMetric::orderBy('name')->get()->map(fn ($m) => $this->present($m))->all();
    }

    public function present(ImpactMetric $m): array
    {
        $values = ImpactValue::where('metric_id', $m->id);

        return ['uuid' => $m->uuid, 'code' => $m->code, 'name' => $m->name, 'unit' => $m->unit, 'kind' => $m->kind, 'currency' => $m->currency, 'description' => $m->description, 'active' => $m->active,
            'months_entered' => (clone $values)->count(), 'latest_month' => (clone $values)->max('period_start')];
    }

    public function find(string $uuid): ImpactMetric
    {
        return ImpactMetric::where('uuid', $uuid)->firstOrFail();
    }

    public function create(User $u, array $d): ImpactMetric
    {
        $code = strtolower($d['code']);
        if (ImpactMetric::where('code', $code)->exists()) {
            $this->fail('code', 'A metric with this code already exists.');
        }
        if ($d['kind'] === 'revenue' && empty($d['currency'])) {
            $this->fail('currency', 'Revenue metrics need a currency so they can be compared with payroll.');
        }
        $m = ImpactMetric::create(['code' => $code, 'name' => $d['name'], 'unit' => $d['unit'], 'kind' => $d['kind'], 'currency' => $d['kind'] === 'revenue' ? strtoupper($d['currency']) : null,
            'description' => $d['description'] ?? null, 'active' => true, 'created_by' => $u->id]);
        Audit::record('impact.metric.create', 'analytics', 'impact:'.$m->uuid, ['code' => $code]);

        return $m;
    }

    public function update(User $u, ImpactMetric $m, array $d): ImpactMetric
    {
        $m->update(array_intersect_key($d, array_flip(['name', 'unit', 'description', 'active'])));
        Audit::record('impact.metric.update', 'analytics', 'impact:'.$m->uuid, array_keys($d));

        return $m->refresh();
    }

    // ---- values ------------------------------------------------------------------------------------------------------------------

    private function periodStart(string $period): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->fail('period', "Period \"$period\" must be YYYY-MM.");
        }
        $start = CarbonImmutable::parse($period.'-01');
        if ($start->lt(CarbonImmutable::parse('2000-01-01')) || $start->gt(CarbonImmutable::parse(TenantClock::today())->startOfMonth())) {
            $this->fail('period', "Period \"$period\" must be between 2000-01 and the current month.");
        }

        return $start;
    }

    public function saveValues(User $u, ImpactMetric $m, array $rows, string $source = 'manual'): int
    {
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            $this->fail('values', 'Too many values in one request.');
        }
        $n = 0;
        DB::transaction(function () use ($u, $m, $rows, $source, &$n) {
            foreach ($rows as $r) {
                $start = $this->periodStart((string) $r['period']);
                ImpactValue::updateOrCreate(['metric_id' => $m->id, 'period_start' => $start->toDateString()], ['value' => $r['value'], 'source' => $source, 'note' => $r['note'] ?? null, 'entered_by' => $u->id]);
                $n++;
            }
            Audit::record('impact.values.save', 'analytics', 'impact:'.$m->uuid, ['rows' => $n, 'source' => $source]);
        });

        return $n;
    }

    public function deleteValue(User $u, ImpactMetric $m, string $period): void
    {
        $start = $this->periodStart($period);
        ImpactValue::where('metric_id', $m->id)->where('period_start', $start->toDateString())->delete();
        Audit::record('impact.value.delete', 'analytics', 'impact:'.$m->uuid, ['period' => $period]);
    }

    /** CSV text with a header `metric_code,period,value[,note]`. Validated completely first; written only on commit and only when every row is valid. */
    public function import(User $u, string $csv, bool $commit): array
    {
        $lines = array_values(array_filter(preg_split('/\R/', trim($csv)), fn ($l) => trim($l) !== ''));
        if (! $lines) {
            $this->fail('csv', 'The file is empty.');
        }
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        foreach (['metric_code', 'period', 'value'] as $required) {
            if (! in_array($required, $header, true)) {
                $this->fail('csv', "Missing column: $required.");
            }
        }
        if (count($lines) > self::MAX_IMPORT_ROWS) {
            $this->fail('csv', 'At most '.self::MAX_IMPORT_ROWS.' rows per import.');
        }
        $metrics = ImpactMetric::all()->keyBy('code');
        $good = [];
        $errors = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $n = $i + 2;
            $cells = array_pad(str_getcsv($line), count($header), '');
            $row = array_combine($header, array_slice($cells, 0, count($header)));
            $code = strtolower(trim($row['metric_code']));
            $problem = null;
            if (! $metrics->has($code)) {
                $problem = "Unknown metric code \"$code\".";
            } elseif (! $metrics[$code]->active) {
                $problem = "Metric \"$code\" is inactive.";
            } elseif (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', trim($row['period']))) {
                $problem = 'Period must be YYYY-MM.';
            } elseif (! is_numeric(trim($row['value'])) || (float) $row['value'] < 0 || (float) $row['value'] > 1.0e12) {
                $problem = 'Value must be a number from 0 to 1,000,000,000,000.';
            } elseif (isset($seen[$code.'|'.trim($row['period'])])) {
                $problem = 'Duplicate metric and period in this file.';
            } else {
                try {
                    $this->periodStart(trim($row['period']));
                } catch (ValidationException $e) {
                    $problem = $e->errors()['period'][0];
                }
            }
            if ($problem) {
                $errors[] = ['row' => $n, 'error' => $problem];

                continue;
            }
            $seen[$code.'|'.trim($row['period'])] = true;
            $good[] = ['metric' => $metrics[$code], 'period' => trim($row['period']), 'value' => trim($row['value']), 'note' => isset($row['note']) && trim($row['note']) !== '' ? mb_substr(trim($row['note']), 0, 300) : null];
        }
        $written = 0;
        if ($commit) {
            if ($errors) {
                $this->fail('csv', 'Fix the rows with errors first. Nothing was saved.');
            }
            DB::transaction(function () use ($u, $good, &$written) {
                foreach (collect($good)->groupBy(fn ($g) => $g['metric']->id) as $set) {
                    $written += $this->saveValues($u, $set[0]['metric'], array_map(fn ($g) => ['period' => $g['period'], 'value' => $g['value'], 'note' => $g['note']], $set->all()), 'import');
                }
            });
        }

        return ['committed' => $commit, 'rows_total' => count($lines), 'rows_valid' => count($good), 'rows_with_errors' => count($errors), 'errors' => array_slice($errors, 0, 50), 'rows_written' => $written];
    }

    // ---- report ------------------------------------------------------------------------------------------------------------------

    public function report(User $u, string $from, string $to): array
    {
        $start = $this->periodStart($from);
        $end = $this->periodStart($to);
        if ($end->lt($start) || $start->diffInMonths($end) + 1 > self::MAX_MONTHS) {
            $this->fail('to', 'Choose 1 to '.self::MAX_MONTHS.' months, ending on or after the start.');
        }
        $today = CarbonImmutable::parse(TenantClock::today());
        $months = [];
        for ($m = $start; $m->lte($end); $m = $m->addMonth()) {
            $monthEnd = $m->endOfMonth();
            $months[] = ['key' => $m->format('Y-m'), 'from' => $m->toDateString(), 'to' => ($monthEnd->gt($today) ? $today : $monthEnd)->toDateString(), 'partial' => $monthEnd->gt($today)];
        }
        $cost = [];
        $heads = [];
        $costNote = null;
        foreach ($months as $m) {
            try {
                $res = $this->analytics->query($u, ['metric' => 'payroll_totals', 'from' => $m['from'], 'to' => $m['to'], 'dimension' => 'total'], false, 'impact');
                $cost[$m['key']] = collect($res['rows'])->filter(fn ($r) => isset($r['value']['gross']))->mapWithKeys(fn ($r) => [$r['group'] => (float) $r['value']['gross']])->all();
                $suppressed = collect($res['rows'])->contains(fn ($r) => ! empty($r['suppressed']));
                if ($suppressed && ! $cost[$m['key']]) {
                    $costNote ??= 'Some months have no published payroll or too few people to show safely, so their cost is withheld (withheld is not zero).';
                }
            } catch (HttpException $e) {
                $cost = null;
                $costNote = 'You do not have access to payroll totals, so workforce cost is not shown. Business results are still listed.';

                break;
            }
        }
        foreach ($months as $m) {
            try {
                $res = $this->analytics->query($u, ['metric' => 'headcount', 'from' => $m['from'], 'to' => $m['to'], 'dimension' => 'total'], false, 'impact');
                $row = $res['rows'][0] ?? null;
                $heads[$m['key']] = $row && ! ($row['suppressed'] ?? false) && ! isset($res['quality']['blocked']) ? (float) $row['value'] : null;
            } catch (HttpException $e) {
                $heads = [];

                break;
            }
        }
        $out = [];
        foreach (ImpactMetric::where('active', true)->orderBy('name')->get() as $metric) {
            $vals = ImpactValue::where('metric_id', $metric->id)->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])->get()->keyBy(fn ($v) => $v->period_start->format('Y-m'));
            $series = [];
            foreach ($months as $m) {
                $v = $vals->get($m['key']);
                $value = $v ? (float) $v->value : null;
                $row = ['month' => $m['key'], 'partial' => $m['partial'], 'value' => $value, 'source' => $v?->source, 'note' => $v?->note, 'headcount' => $heads[$m['key']] ?? null,
                    'per_head' => $value !== null && ($heads[$m['key']] ?? 0) > 0 ? round($value / $heads[$m['key']], 4) : null, 'cost' => null, 'cost_per_unit' => null, 'payroll_pct_of_revenue' => null, 'revenue_per_payroll_unit' => null];
                if ($cost !== null && isset($cost[$m['key']])) {
                    $gross = $metric->kind === 'revenue' ? ($cost[$m['key']][$metric->currency] ?? null) : (count($cost[$m['key']]) === 1 ? array_values($cost[$m['key']])[0] : null);
                    $cur = $metric->kind === 'revenue' ? $metric->currency : (count($cost[$m['key']]) === 1 ? array_key_first($cost[$m['key']]) : null);
                    $row['cost'] = $gross !== null ? ['gross' => round($gross, 2), 'currency' => $cur] : null;
                    if ($gross !== null && $value !== null && $value > 0 && ! $m['partial']) {
                        $row['cost_per_unit'] = $metric->kind === 'output' ? round($gross / $value, 4) : null;
                        $row['payroll_pct_of_revenue'] = $metric->kind === 'revenue' ? round($gross / $value * 100, 2) : null;
                        $row['revenue_per_payroll_unit'] = $metric->kind === 'revenue' && $gross > 0 ? round($value / $gross, 4) : null;
                    }
                }
                $series[] = $row;
            }
            $withData = array_values(array_filter($series, fn ($r) => $r['value'] !== null));
            $ratioKey = $metric->kind === 'revenue' ? 'payroll_pct_of_revenue' : 'cost_per_unit';
            $ratios = array_values(array_filter($series, fn ($r) => $r[$ratioKey] !== null));
            $first = $ratios[0] ?? null;
            $last = $ratios ? $ratios[count($ratios) - 1] : null;
            $out[] = $this->present($metric) + ['series' => $series, 'summary' => ['months_with_results' => count($withData), 'months_with_ratio' => count($ratios), 'headline_ratio' => $ratioKey,
                'first' => $first ? ['month' => $first['month'], 'value' => $first[$ratioKey]] : null, 'last' => $last ? ['month' => $last['month'], 'value' => $last[$ratioKey]] : null,
                'change_percent' => $first && $last && $first['month'] !== $last['month'] && $first[$ratioKey] > 0 ? round(($last[$ratioKey] - $first[$ratioKey]) / $first[$ratioKey] * 100, 1) : null]];
        }
        Audit::record('impact.report', 'analytics', 'user:'.$u->id, ['from' => $from, 'to' => $to]);

        return ['period' => ['from' => $from, 'to' => $to], 'months' => array_column($months, 'key'), 'cost_available' => $cost !== null, 'cost_note' => $costNote, 'metrics' => $out,
            'definitions' => ['cost_per_unit' => 'Gross payroll for the month divided by the output entered. Months still in progress are not ratioed.', 'payroll_pct_of_revenue' => 'Gross payroll divided by revenue, in the same currency. No currency conversion.',
                'per_head' => 'Result divided by headcount at the end of the month.'],
            'limitations' => 'Results are entered by your team, so the ratios are only as good as those entries. Cost covers published payroll inside your data scope; people outside it are not included. Ratios show how cost and results move together. They do not show that one caused the other.'];
    }
}
