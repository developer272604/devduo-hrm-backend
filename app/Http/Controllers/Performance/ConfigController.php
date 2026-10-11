<?php

namespace App\Http\Controllers\Performance;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Performance\{CycleService, PerformanceAccess};
use App\Domain\Performance\Contracts\PerformanceOutcomeProvider;
use App\Domain\Performance\Models\{CalibrationChange, Competency, Cycle, Goal, RatingScale, Response, Review, Template};
use App\Domain\Performance\Support\Num;
use App\Domain\Tenancy\TenantClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Configuration (scales, competencies, templates, cycles), manager/HR dashboard and the outcome contract endpoint. */
final class ConfigController
{
    public function __construct(private PerformanceAccess $access, private CycleService $cycles) {}

    public function scales()
    {
        return RatingScale::orderBy('code')->get()->map(fn ($s) => ['uuid' => $s->uuid, 'code' => $s->code, 'name' => $s->name, 'min' => Num::round(Num::in($s->min_value), 2), 'max' => Num::round(Num::in($s->max_value), 2), 'labels' => $s->labels, 'active' => $s->active]);
    }

    public function createScale(Request $r)
    {
        $d = $r->validate(['code' => 'required|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'name' => 'required|string|max:120', 'min' => 'required|numeric', 'max' => 'required|numeric|gt:min',
            'labels' => 'required|array|min:2|max:10', 'labels.*.value' => 'required|numeric', 'labels.*.label' => 'required|string|max:80']);
        if (RatingScale::where('code', $d['code'])->exists()) throw ValidationException::withMessages(['code' => 'A scale with this code already exists.']);
        foreach ($d['labels'] as $l) if (Decimal::cmp(Num::in($l['value']), Num::in($d['min'])) < 0 || Decimal::cmp(Num::in($l['value']), Num::in($d['max'])) > 0) throw ValidationException::withMessages(['labels' => 'Every label must sit inside the scale range.']);
        $s = RatingScale::create(['code' => $d['code'], 'name' => $d['name'], 'min_value' => Num::in($d['min']), 'max_value' => Num::in($d['max']), 'labels' => array_map(fn ($l) => ['value' => Num::round(Num::in($l['value']), 2), 'label' => $l['label']], $d['labels'])]);
        Audit::record('scale.created', 'performance', 'scale:'.$s->uuid);

        return response()->json(['uuid' => $s->uuid], 201);
    }

    public function competencies()
    {
        return Competency::orderBy('category')->orderBy('code')->get(['uuid', 'code', 'name', 'description', 'category', 'active']);
    }

    public function createCompetency(Request $r)
    {
        $d = $r->validate(['code' => 'required|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'name' => 'required|string|max:120', 'description' => 'nullable|string|max:2000', 'category' => ['nullable', Rule::in(['competency', 'value'])]]);
        if (Competency::where('code', $d['code'])->exists()) throw ValidationException::withMessages(['code' => 'A competency with this code already exists.']);
        $c = Competency::create($d);
        Audit::record('competency.created', 'performance', 'competency:'.$c->uuid);

        return response()->json(['uuid' => $c->uuid], 201);
    }

    public function templates()
    {
        return Template::with('scale')->orderBy('name')->get()->map(fn ($t) => ['uuid' => $t->uuid, 'name' => $t->name, 'scale_code' => $t->scale->code, 'sections' => $t->sections, 'reviewer_weights' => $t->reviewer_weights, 'active' => $t->active]);
    }

    public function createTemplate(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:160', 'scale_code' => 'required|string', 'sections' => 'required|array|min:1|max:12', 'sections.*.key' => 'required|regex:/^[a-z][a-z0-9_]{0,39}$/', 'sections.*.title' => 'required|string|max:120',
            'sections.*.type' => ['required', Rule::in(['goals', 'competencies', 'values', 'overall'])], 'sections.*.weight' => 'required|numeric|min:0.01|max:100', 'sections.*.mandatory' => 'boolean',
            'reviewer_weights' => 'required|array', 'reviewer_weights.manager' => 'required|numeric|min:0|max:100', 'reviewer_weights.self' => 'required|numeric|min:0|max:100', 'reviewer_weights.peer' => 'required|numeric|min:0|max:100', 'reviewer_weights.skip' => 'required|numeric|min:0|max:100']);
        $scale = RatingScale::where('code', $d['scale_code'])->where('active', true)->first() ?? throw ValidationException::withMessages(['scale_code' => 'Unknown rating scale.']);
        $total = Decimal::sum(array_map(fn ($v) => Num::in($v), array_intersect_key($d['reviewer_weights'], array_flip(['manager', 'self', 'peer', 'skip']))));
        if (! $total->isEqualTo(100)) throw ValidationException::withMessages(['reviewer_weights' => 'Reviewer weights must total 100.']);
        if (count(array_unique(array_column($d['sections'], 'key'))) !== count($d['sections'])) throw ValidationException::withMessages(['sections' => 'Section keys must be unique.']);
        $t = Template::create(['name' => $d['name'], 'scale_id' => $scale->id, 'created_by' => $r->user()->id,
            'sections' => array_map(fn ($s) => ['key' => $s['key'], 'title' => $s['title'], 'type' => $s['type'], 'weight' => Num::round(Num::in($s['weight']), 2), 'mandatory' => (bool) ($s['mandatory'] ?? false)], $d['sections']),
            'reviewer_weights' => array_map(fn ($v) => Num::round(Num::in($v), 2), array_intersect_key($d['reviewer_weights'], array_flip(['manager', 'self', 'peer', 'skip'])))]);
        Audit::record('template.created', 'performance', 'template:'.$t->uuid);

        return response()->json(['uuid' => $t->uuid], 201);
    }

    private function cycle(Cycle $c): array
    {
        return ['uuid' => $c->uuid, 'name' => $c->name, 'kind' => $c->kind, 'status' => $c->status, 'start_date' => $c->start_date->toDateString(), 'end_date' => $c->end_date->toDateString(), 'template' => ['uuid' => $c->template->uuid, 'name' => $c->template->name],
            'self_due' => $c->self_due?->toDateString(), 'manager_due' => $c->manager_due?->toDateString(), 'peer_due' => $c->peer_due?->toDateString(), 'calibration_due' => $c->calibration_due?->toDateString(), 'eligibility' => $c->eligibility, 'settings' => $c->settings,
            'reviews' => Review::where('cycle_id', $c->id)->count()];
    }

    public function cycleList()
    {
        return Cycle::with('template')->orderByDesc('start_date')->paginate(25)->through(fn ($c) => $this->cycle($c));
    }

    public function cycleShow(string $uuid)
    {
        return $this->cycle(Cycle::with('template')->where('uuid', $uuid)->firstOrFail());
    }

    public function createCycle(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:160', 'kind' => ['nullable', Rule::in(['annual', 'half_year', 'quarterly', 'custom'])], 'template_uuid' => 'required|uuid', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'self_due' => 'nullable|date_format:Y-m-d', 'manager_due' => 'nullable|date_format:Y-m-d', 'peer_due' => 'nullable|date_format:Y-m-d', 'calibration_due' => 'nullable|date_format:Y-m-d',
            'eligibility' => 'nullable|array', 'eligibility.legal_entity_ids' => 'array', 'eligibility.department_ids' => 'array', 'eligibility.employee_type_ids' => 'array', 'eligibility.location_ids' => 'array',
            'eligibility.min_tenure_days' => 'nullable|integer|min:0|max:3650', 'eligibility.exclude_probation' => 'boolean',
            'settings' => 'nullable|array', 'settings.min_peer_responses' => 'nullable|integer|min:1|max:10', 'settings.peer_anonymous' => 'boolean', 'settings.share_peer_feedback' => 'boolean']);
        $tpl = Template::byUuid($d['template_uuid']);
        $c = Cycle::create(['name' => $d['name'], 'kind' => $d['kind'] ?? 'annual', 'template_id' => $tpl->id, 'start_date' => $d['start_date'], 'end_date' => $d['end_date'], 'self_due' => $d['self_due'] ?? null, 'manager_due' => $d['manager_due'] ?? null,
            'peer_due' => $d['peer_due'] ?? null, 'calibration_due' => $d['calibration_due'] ?? null, 'eligibility' => $d['eligibility'] ?? null, 'settings' => $d['settings'] ?? null, 'created_by' => $r->user()->id]);
        Audit::record('cycle.created', 'performance', 'cycle:'.$c->uuid);

        return response()->json($this->cycle($c->load('template')), 201);
    }

    public function publish(string $uuid)
    {
        return $this->cycle($this->cycles->publish(Cycle::with('template')->where('uuid', $uuid)->firstOrFail())->refresh());
    }

    public function launch(string $uuid)
    {
        $c = Cycle::with('template')->where('uuid', $uuid)->firstOrFail();
        $stats = $this->cycles->launch($c);

        return $stats + ['cycle' => $this->cycle($c->refresh())];
    }

    /** Eligible-people preview (effective-dated as of the reference date) before launching. */
    public function eligibility(string $uuid)
    {
        $c = Cycle::with('template')->where('uuid', $uuid)->firstOrFail();
        $e = $this->cycles->eligible($c);

        return ['as_of' => $this->cycles->referenceDate($c), 'count' => $e->count(), 'already_reviewed' => Review::where('cycle_id', $c->id)->count()];
    }

    /** Manager/HR dashboard: completion, late responses, distribution (privacy threshold), calibration, goals. All limited to the viewer's data scope. */
    public function dashboard(Request $r)
    {
        $u = $r->user(); $today = TenantClock::today();
        $cq = Cycle::query()->whereIn('status', ['open', 'calibration', 'closed'])->orderByDesc('start_date');
        $cycle = $r->filled('cycle_uuid') ? $cq->where('uuid', $r->query('cycle_uuid'))->firstOrFail() : $cq->first();
        $reach = $this->access->reachIds($u, 'performance.reports.view');
        $out = ['cycle' => $cycle ? ['uuid' => $cycle->uuid, 'name' => $cycle->name, 'status' => $cycle->status] : null, 'privacy_min_group' => 3];
        if ($cycle) {
            $reviews = Review::where('cycle_id', $cycle->id)->whereIn('employee_id', $reach);
            $ids = (clone $reviews)->pluck('id');
            $resp = Response::whereIn('review_id', $ids);
            $total = (clone $reviews)->count();
            $out['reviews'] = ['total' => $total, 'by_status' => (clone $reviews)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all()];
            $out['completion'] = [];
            foreach (['self', 'manager', 'peer', 'skip'] as $t) {
                $all = (clone $resp)->where('reviewer_type', $t)->count();
                if ($all) $out['completion'][$t] = ['total' => $all, 'submitted' => (clone $resp)->where('reviewer_type', $t)->where('status', 'submitted')->count()];
            }
            $out['late_responses'] = (clone $resp)->where('status', 'pending')->where('due_on', '<', $today)->count();
            $scores = (clone $reviews)->get(['final_score', 'computed_score'])->map(fn ($x) => $x->final_score ?? $x->computed_score)->filter(fn ($s) => $s !== null);
            if ($scores->count() < 3) $out['distribution'] = ['suppressed' => true, 'reason' => 'Fewer than 3 scored reviews in your scope; distribution is hidden to protect individuals.'];
            else {
                $dist = [];
                foreach ($scores as $s) { $k = Num::round(Decimal::of(Num::in($s))->toScale(0, \Brick\Math\RoundingMode::HALF_UP), 0); $dist[$k] = ($dist[$k] ?? 0) + 1; }
                ksort($dist);
                $out['distribution'] = ['suppressed' => false, 'buckets' => $dist, 'average' => Num::round(Decimal::div(Decimal::sum($scores->map(fn ($s) => Num::in($s))), $scores->count(), 6), 2)];
            }
            $out['calibration'] = ['pending_changes' => CalibrationChange::where('status', 'pending')->whereIn('review_id', $ids)->count(), 'approved_changes' => CalibrationChange::where('status', 'approved')->whereIn('review_id', $ids)->count()];
        }
        $goals = Goal::query()->whereIn('owner_employee_id', $reach);
        $out['goals'] = ['by_status' => (clone $goals)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(), 'pending_approval' => (clone $goals)->where('status', 'pending_approval')->count()];

        return $out;
    }

    public function outcomes(Request $r)
    {
        $u = $r->user(); $provider = app(PerformanceOutcomeProvider::class);
        $rows = [];
        foreach (\App\Domain\People\Models\Employee::query()->whereIn('id', $this->access->reachIds($u, 'performance.outcomes.view'))->whereIn('id', Review::whereIn('status', Review::FINAL)->select('employee_id'))->orderBy('id')->limit(500)->get() as $e) {
            foreach ($provider->outcomesFor($e->id, $r->query('as_of')) as $o) if (! $r->filled('cycle_uuid') || $o['cycle'] === $r->query('cycle_uuid')) $rows[] = ['employee_uuid' => $e->uuid] + $o;
        }
        \App\Domain\Audit\Audit::record('outcomes.read', 'performance', 'outcomes', ['rows' => count($rows)]);

        return ['data' => $rows, 'notice' => 'Review outcomes are inputs for human-approved decisions. They never change pay, grade or employment automatically.'];
    }
}
