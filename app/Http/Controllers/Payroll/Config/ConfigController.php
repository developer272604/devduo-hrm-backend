<?php

namespace App\Http\Controllers\Payroll\Config;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Organization\OrganizationRecord;
use App\Domain\Payroll\Components\ComponentService;
use App\Domain\Payroll\Contracts\FormulaEvaluator;
use App\Domain\Payroll\CountryPacks\{DeclarativePack, PackService, SamplePacks};
use App\Domain\Payroll\Groups\GroupResolver;
use App\Domain\Payroll\Models\{CountryPack, CountryPackVersion, ExchangeRate, PayrollCalendar, PayrollGroup, PayrollPeriod, SalaryComponent};
use App\Domain\Payroll\Periods\PeriodService;
use App\Domain\Payroll\Support\{Decimal, PayrollSettings, PayrollStates, Rounding};
use App\Domain\Payroll\Contracts\Dto\StatutoryContext;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ConfigController
{
    private const CURRENCIES = ['INR', 'USD', 'GBP', 'EUR', 'SGD', 'AUD', 'CAD', 'JPY', 'AED'];

    // ---- groups ------------------------------------------------------------------------------------------------

    private function groupShape(PayrollGroup $g): array
    {
        return $g->only(['uuid', 'name', 'code', 'legal_entity_id', 'country', 'currency', 'timezone', 'frequency', 'active', 'cutoff_day', 'employee_category', 'worker_type', 'settings', 'description'])
            + ['country_pack_uuid' => $g->country_pack_id ? CountryPack::whereKey($g->country_pack_id)->value('uuid') : null];
    }

    public function groups() { return PayrollGroup::orderBy('name')->paginate(25)->through(fn ($g) => $this->groupShape($g)); }

    public function showGroup(string $uuid) { return $this->groupShape(PayrollGroup::byUuid($uuid)); }

    public function createGroup(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:120', 'code' => 'required|regex:/^[A-Z][A-Z0-9_]{0,39}$/', 'legal_entity_id' => 'required|integer', 'country' => 'required|regex:/^[A-Z]{2}$/', 'currency' => ['required', Rule::in(self::CURRENCIES)],
            'timezone' => 'required|timezone', 'frequency' => ['required', Rule::in(PayrollStates::FREQUENCIES)], 'employee_category' => 'nullable|string|max:60', 'worker_type' => 'nullable|string|max:40',
            'cutoff_day' => 'nullable|integer|min:1|max:31', 'settings' => 'nullable|array', 'description' => 'nullable|string|max:1000']);
        OrganizationRecord::forType('legal-entities')->newQuery()->whereKey($data['legal_entity_id'])->firstOrFail();
        if (PayrollGroup::where('code', $data['code'])->exists()) throw ValidationException::withMessages(['code' => 'Code already exists.']);
        $record = DB::transaction(function () use ($data) { $g = PayrollGroup::create($data); Audit::record('group.created', 'payroll', $g->uuid); return $g; });

        return response()->json(['uuid' => $record->uuid], 201);
    }

    public function updateGroup(Request $r, string $uuid)
    {
        $g = PayrollGroup::byUuid($uuid);
        $d = $r->validate(['name' => 'sometimes|string|max:120', 'active' => 'sometimes|boolean', 'cutoff_day' => 'sometimes|nullable|integer|min:1|max:31', 'settings' => 'sometimes|nullable|array', 'description' => 'sometimes|nullable|string|max:1000',
            'employee_category' => 'sometimes|nullable|string|max:60', 'worker_type' => 'sometimes|nullable|string|max:40', 'country_pack_uuid' => 'sometimes|nullable|uuid']);
        if (array_key_exists('country_pack_uuid', $d)) {
            $pack = $d['country_pack_uuid'] ? CountryPack::byUuid($d['country_pack_uuid']) : null;
            if ($pack && $pack->country !== $g->country) throw ValidationException::withMessages(['country_pack_uuid' => 'The pack country does not match the group country.']);
            $d['country_pack_id'] = $pack?->id; unset($d['country_pack_uuid']);
        }
        $g->update($d); Audit::record('group.updated', 'payroll', $g->uuid);

        return $this->groupShape($g);
    }

    public function groupEmployees(Request $r, string $uuid, GroupResolver $resolver)
    {
        $g = PayrollGroup::byUuid($uuid);
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = $d['from'] ?? now()->toDateString(); $to = $d['to'] ?? $from;
        $ids = $resolver->employeeIds($g->id, $from, $to);
        $q = app(DataScope::class)->constrain(Employee::query()->whereIn('employees.id', $ids), $r->user(), 'payroll.configure')->with('person');

        return $q->orderBy('employee_number')->paginate(50)->through(fn ($e) => ['uuid' => $e->uuid, 'name' => $e->person->display_name, 'number' => $e->employee_number]);
    }

    public function assignEmployee(Request $r, string $uuid, GroupResolver $resolver)
    {
        $g = PayrollGroup::byUuid($uuid);
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d']);
        $e = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'payroll.configure')->where('uuid', $d['employee_uuid'])->firstOrFail();
        $resolver->assign($e, $g, $d['effective_from'], $d['effective_to'] ?? null);

        return response()->json(['employee_uuid' => $e->uuid, 'group_uuid' => $g->uuid, 'effective_from' => $d['effective_from']], 201);
    }

    public function employeeGroup(Request $r, string $uuid, GroupResolver $resolver)
    {
        $d = $r->validate(['date' => 'nullable|date_format:Y-m-d']);
        $e = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'payroll.configure')->where('uuid', $uuid)->firstOrFail();
        $id = $resolver->groupIdFor($e->id, $d['date'] ?? now()->toDateString());

        return ['employee_uuid' => $e->uuid, 'date' => $d['date'] ?? now()->toDateString(), 'group' => $id ? $this->groupShape(PayrollGroup::findOrFail($id)) : null];
    }

    public function groupConflicts(GroupResolver $resolver)
    {
        $rows = collect($resolver->conflicts())->map(fn ($c) => ['employee_uuid' => Employee::whereKey($c['employee_id'])->value('uuid'), 'group_uuids' => PayrollGroup::whereIn('id', $c['groups'])->pluck('uuid')->all(), 'from' => $c['from']])->all();

        return ['conflicts' => $rows];
    }

    // ---- calendars and periods ---------------------------------------------------------------------------------------

    public function calendars(string $uuid)
    {
        $g = PayrollGroup::byUuid($uuid);

        return ['data' => PayrollCalendar::where('payroll_group_id', $g->id)->orderByDesc('effective_from')->get()->map(fn ($c) => $c->only(['uuid', 'name', 'frequency', 'anchor_date', 'effective_from', 'effective_to', 'rules', 'status']))->all()];
    }

    public function createCalendar(Request $r, string $uuid, PeriodService $svc)
    {
        $g = PayrollGroup::byUuid($uuid);
        $d = $r->validate(['name' => 'required|string|max:120', 'frequency' => ['required', Rule::in(PayrollStates::FREQUENCIES)], 'anchor_date' => 'nullable|date_format:Y-m-d', 'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from', 'rules' => 'nullable|array']);
        if ($d['frequency'] !== $g->frequency) throw ValidationException::withMessages(['frequency' => 'The calendar frequency must match the group frequency ('.$g->frequency.').']);

        return response()->json(['uuid' => $svc->createCalendar($g, $d)->uuid], 201);
    }

    public function generatePeriods(Request $r, string $uuid, PeriodService $svc)
    {
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);

        return response()->json($svc->generate(PayrollCalendar::byUuid($uuid), $d['from'], $d['to']), 201);
    }

    public function periods(Request $r, PeriodService $svc)
    {
        $d = $r->validate(['group_uuid' => 'nullable|uuid', 'state' => ['nullable', Rule::in(PayrollStates::PERIOD)], 'period_type' => 'nullable|in:regular,off_cycle,bonus,correction', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $q = PayrollPeriod::query();
        if (! empty($d['group_uuid'])) $q->where('payroll_group_id', PayrollGroup::byUuid($d['group_uuid'])->id);
        foreach (['state', 'period_type'] as $f) if (! empty($d[$f])) $q->where($f, $d[$f]);
        if (! empty($d['from'])) $q->where('period_end', '>=', $d['from']);
        if (! empty($d['to'])) $q->where('period_start', '<=', $d['to']);

        return $q->orderBy('period_start')->orderBy('period_type')->orderBy('cycle_no')->paginate(min((int) $r->query('per_page', 50), 100))->through(fn ($p) => $svc->present($p));
    }

    public function createPeriod(Request $r, string $uuid, PeriodService $svc)
    {
        $g = PayrollGroup::byUuid($uuid);
        $d = $r->validate(['period_type' => 'required|in:off_cycle,bonus,correction', 'label' => 'required|string|max:80', 'parent_period_uuid' => 'nullable|uuid', 'period_start' => 'nullable|date_format:Y-m-d',
            'period_end' => 'nullable|date_format:Y-m-d', 'payment_date' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:1000']);

        return response()->json($svc->present($svc->createSpecial($g, $d)), 201);
    }

    public function showPeriod(string $uuid, PeriodService $svc)
    {
        $p = PayrollPeriod::byUuid($uuid);

        return $svc->present($p) + ['transitions' => DB::table('payroll_period_transitions')->where('period_id', $p->id)->orderBy('id')->get(['from_state', 'to_state', 'reason', 'created_at'])->all()];
    }

    public function transitionPeriod(Request $r, string $uuid, PeriodService $svc)
    {
        $d = $r->validate(['to' => ['required', Rule::in(PayrollStates::PERIOD)], 'reason' => 'nullable|string|max:1000']);

        return $svc->present($svc->transition($r->user(), PayrollPeriod::byUuid($uuid), $d['to'], $d['reason'] ?? null));
    }

    // ---- components ------------------------------------------------------------------------------------------------

    public function components(ComponentService $svc, Request $r)
    {
        $q = SalaryComponent::orderBy('code')->orderByDesc('version');
        if ($r->filled('kind')) $q->where('kind', $r->query('kind'));

        return $q->paginate(25)->through(fn ($c) => $svc->present($c));
    }

    public function showComponent(string $uuid, ComponentService $svc) { return $svc->present(SalaryComponent::byUuid($uuid)); }

    private function componentRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return ['name' => "$req|string|max:120", 'kind' => [$req, Rule::in(PayrollStates::COMPONENT_KINDS)], 'currency' => "$req|regex:/^[A-Z]{3}$/", 'effective_from' => "$req|date_format:Y-m-d",
            'effective_to' => 'nullable|date_format:Y-m-d', 'formula' => 'nullable|string|max:1000', 'payslip_visible' => 'boolean', 'gl_code' => 'nullable|string|max:80', 'nature' => 'sometimes|in:fixed,variable',
            'country' => 'nullable|regex:/^[A-Z]{2}$/', 'calc_method' => ['sometimes', Rule::in(ComponentService::CALC_METHODS)], 'tax_classification' => 'nullable|string|max:40',
            'proration' => 'sometimes|in:none,calendar_days,working_days,payable_days', 'attendance_dependent' => 'boolean', 'lop_dependent' => 'boolean', 'overtime_dependent' => 'boolean',
            'rounding_mode' => ['nullable', Rule::in(Rounding::MODES)], 'rounding_scale' => 'nullable|integer|min:0|max:6', 'rounding_increment' => ['nullable', 'string', 'regex:/^\d{1,6}(?:\.\d{1,6})?$/D'],
            'min_amount' => 'nullable|string', 'max_amount' => 'nullable|string', 'statutory_mapping' => 'nullable|string|max:80', 'active' => 'boolean', 'sequence' => 'integer|min:1|max:9999', 'config' => 'nullable|array'];
    }

    public function createComponent(Request $r, ComponentService $svc)
    {
        $data = $r->validate($this->componentRules(true) + ['code' => 'required|regex:/^[A-Z][A-Z0-9_]{0,39}$/']);

        return response()->json(['uuid' => $svc->create($data, $r->user()->id)->uuid], 201);
    }

    public function updateComponent(Request $r, string $uuid, ComponentService $svc)
    {
        return $svc->present($svc->update(SalaryComponent::byUuid($uuid), $r->validate($this->componentRules(false))));
    }

    public function componentVersion(Request $r, string $uuid, ComponentService $svc)
    {
        $data = $r->validate(['effective_from' => 'required|date_format:Y-m-d'] + array_diff_key($this->componentRules(false), ['effective_from' => 1]));

        return response()->json($svc->present($svc->newVersion(SalaryComponent::byUuid($uuid), $data, $r->user()->id)), 201);
    }

    public function publishComponent(string $uuid, ComponentService $svc) { return $svc->present($svc->publish(SalaryComponent::byUuid($uuid))); }

    /** Validates (and optionally evaluates) a formula against the component catalogue. Never persists. */
    public function checkFormula(Request $r, ComponentService $svc, FormulaEvaluator $formulas)
    {
        $d = $r->validate(['formula' => 'required|string|max:1000', 'variables' => 'nullable|array|max:40', 'variables.*' => ['string', 'regex:/^-?\d{1,18}(?:\.\d{1,6})?$/D'], 'component_code' => 'nullable|string|max:40']);
        $allowed = array_merge($svc->allowedVariables($d['component_code'] ?? null), array_keys($d['variables'] ?? []));
        foreach ($formulas->references($d['formula']) as $ref) if (str_starts_with($ref, 'RULE_')) $allowed[] = $ref;
        $problems = $formulas->validate($d['formula'], $allowed);
        $out = ['valid' => $problems === [], 'problems' => $problems, 'references' => $formulas->references($d['formula']), 'persisted' => false, 'allowed_variables' => array_values(array_unique($allowed))];
        if (! $problems && isset($d['variables'])) { try { $out['result'] = $formulas->evaluate($d['formula'], $d['variables'], 6)->trace(); } catch (\InvalidArgumentException $e) { $out['valid'] = false; $out['problems'][] = $e->getMessage(); } }

        return $out;
    }

    // ---- country packs ---------------------------------------------------------------------------------------------

    public function packs(PackService $svc) { return ['data' => CountryPack::orderBy('code')->get()->map(fn ($p) => $svc->presentPack($p))->all()]; }

    public function showPack(string $uuid, PackService $svc) { return $svc->presentPack(CountryPack::byUuid($uuid)); }

    public function createPack(Request $r, PackService $svc)
    {
        $d = $r->validate(['code' => 'required|regex:/^[A-Z][A-Z0-9_-]{0,39}$/', 'country' => 'required|regex:/^[A-Z]{2}$/', 'name' => 'required|string|max:160', 'handler' => 'nullable|string|max:80', 'description' => 'nullable|string|max:2000']);
        return response()->json($svc->presentPack($svc->createPack($d)), 201);
    }

    public function installSample(PackService $svc)
    {
        $def = SamplePacks::definition();
        $pack = CountryPack::where('code', $def['pack']['code'])->first() ?? $svc->createPack($def['pack']);
        if (! $pack->versions()->exists()) $svc->createVersion($pack, $def['version']);

        return response()->json($svc->presentPack($pack->refresh()), 201);
    }

    private function versionRules(): array
    {
        return ['effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d', 'tax_year_start' => ['nullable', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'], 'metadata' => 'nullable|array',
            'calculation_rules' => 'nullable|array', 'validation_rules' => 'nullable|array', 'identifiers' => 'nullable|array', 'declaration_schema' => 'nullable|array'];
    }

    public function createVersion(Request $r, string $uuid, PackService $svc)
    {
        $v = $svc->createVersion(CountryPack::byUuid($uuid), $r->validate($this->versionRules()));

        return response()->json($svc->presentVersion($v), 201);
    }

    public function showVersion(string $uuid, PackService $svc) { $v = CountryPackVersion::byUuid($uuid); return $svc->presentVersion($v) + ['problems' => $svc->problems($v)]; }

    public function updateVersion(Request $r, string $uuid, PackService $svc)
    {
        return $svc->presentVersion($svc->updateDraft(CountryPackVersion::byUuid($uuid), $r->validate(array_map(fn ($x) => is_string($x) ? str_replace('required|', 'sometimes|', $x) : $x, $this->versionRules()))));
    }

    public function checkVersion(string $uuid, PackService $svc) { $p = $svc->problems(CountryPackVersion::byUuid($uuid)); return ['valid' => $p === [], 'problems' => $p]; }

    public function publishVersion(Request $r, string $uuid, PackService $svc) { return $svc->presentVersion($svc->publish(CountryPackVersion::byUuid($uuid), $r->user())); }

    public function verifyVersion(Request $r, string $uuid, PackService $svc)
    {
        $d = $r->validate(['note' => 'required|string|min:10|max:2000']);

        return $svc->presentVersion($svc->verify(CountryPackVersion::byUuid($uuid), $r->user(), $d['note']));
    }

    public function retireVersion(Request $r, string $uuid, PackService $svc)
    {
        $d = $r->validate(['effective_to' => 'required|date_format:Y-m-d']);

        return $svc->presentVersion($svc->retire(CountryPackVersion::byUuid($uuid), $d['effective_to']));
    }

    public function enablePack(Request $r, string $uuid, PackService $svc) { return $svc->presentPack($svc->enable(CountryPack::byUuid($uuid), $r->user())); }

    public function disablePack(string $uuid, PackService $svc) { return $svc->presentPack($svc->disable(CountryPack::byUuid($uuid))); }

    /** Dry-run of a pack version's rules against supplied amounts. Never persists. */
    public function simulatePack(Request $r, string $uuid, DeclarativePack $declarative)
    {
        $v = CountryPackVersion::byUuid($uuid);
        $d = $r->validate(['currency' => 'required|regex:/^[A-Z]{3}$/', 'periods_in_year' => 'required|integer|min:1|max:366', 'amounts' => 'required|array|max:30', 'amounts.*' => ['string', 'regex:/^-?\d{1,14}(?:\.\d{1,6})?$/D']]);
        $ctx = new StatutoryContext($v->pack->country, $d['currency'], substr((string) $v->effective_from, 0, 10), substr((string) $v->effective_from, 0, 10), substr((string) $v->effective_from, 0, 4), $d['periods_in_year'], 1,
            $v->id, $v->version, $v->calculation_rules ?? [], $v->validation_rules ?? [], $d['amounts']);
        $res = $declarative->calculate($ctx);

        return ['lines' => $res->lines, 'warnings' => $res->warnings, 'errors' => $res->errors, 'persisted' => false, 'non_authoritative' => (bool) ($v->metadata['non_authoritative'] ?? false)];
    }

    // ---- settings and exchange rates ---------------------------------------------------------------------------------

    public function settings() { return ['calculation' => PayrollSettings::get('calculation'), 'currency' => PayrollSettings::get('currency')]; }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['calculation' => 'sometimes|array', 'calculation.rounding_mode' => ['sometimes', Rule::in(Rounding::MODES)], 'calculation.negative_net' => 'sometimes|in:block,warn', 'calculation.zero_pay' => 'sometimes|in:warn,ignore',
            'calculation.days_per_year' => ['sometimes', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/D'], 'calculation.hours_per_day' => ['sometimes', 'string', 'regex:/^\d{1,2}(?:\.\d{1,2})?$/D'], 'calculation.require_pack_verification' => 'sometimes|boolean',
            'currency' => 'sometimes|array', 'currency.allow_cross_currency' => 'sometimes|boolean', 'currency.reporting_currency' => 'sometimes|nullable|regex:/^[A-Z]{3}$/']);
        DB::transaction(function () use ($d) {
            $all = PayrollSettings::all();
            foreach (['calculation', 'currency'] as $s) if (isset($d[$s])) $all[$s] = array_replace($all[$s], $d[$s]);
            PayrollSettings::save($all); Audit::record('settings.updated', 'payroll', 'settings', ['sections' => array_keys($d)]);
        });

        return $this->settings();
    }

    public function exchangeRates(Request $r)
    {
        return ExchangeRate::orderByDesc('as_of')->paginate(50)->through(fn ($x) => $x->only(['base_currency', 'quote_currency', 'rate', 'as_of', 'source']));
    }

    public function saveExchangeRate(Request $r)
    {
        $d = $r->validate(['base_currency' => 'required|regex:/^[A-Z]{3}$/|different:quote_currency', 'quote_currency' => 'required|regex:/^[A-Z]{3}$/', 'rate' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,10})?$/D'], 'as_of' => 'required|date_format:Y-m-d', 'source' => 'nullable|string|max:60']);
        if (Decimal::isZero($d['rate'])) throw ValidationException::withMessages(['rate' => 'Rate must be positive.']);
        if (ExchangeRate::where('base_currency', $d['base_currency'])->where('quote_currency', $d['quote_currency'])->where('as_of', $d['as_of'])->exists()) throw ValidationException::withMessages(['as_of' => 'A rate already exists for that date (rates are not overwritten).']);
        ExchangeRate::create($d); Audit::record('exchange_rate.created', 'payroll', $d['base_currency'].'/'.$d['quote_currency']);

        return response()->json($d, 201);
    }
}
