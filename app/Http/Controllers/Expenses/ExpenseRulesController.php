<?php
namespace App\Http\Controllers\Expenses;

use App\Domain\Expenses\{ExpenseRuleService, FxService};
use App\Domain\Expenses\Models\{ExpenseFxRate, ExpenseLimitRule, ExpenseRateTable};
use Illuminate\Http\Request;

final class ExpenseRulesController {
    private const CURRENCIES = 'in:USD,EUR,GBP,INR,JPY,KWD';
    public function __construct(private ExpenseRuleService $rules, private FxService $fx) {}
    public function limits(Request $r) { return ExpenseLimitRule::orderByDesc('id')->limit(200)->get()->map(fn ($x) => $this->rules->limitView($x)); }
    public function createLimit(Request $r) {
        $d = $r->validate(['rule_key' => 'nullable|uuid', 'name' => 'required_without:rule_key|string|max:150', 'category' => 'required_without:rule_key|string|max:80', 'country' => 'nullable|string|size:2|alpha',
            'legal_entity_id' => 'nullable|integer', 'grade_id' => 'nullable|integer', 'currency' => 'required_without:rule_key|'.self::CURRENCIES, 'per_line_limit' => 'nullable|string|max:20', 'category_total_limit' => 'nullable|string|max:20',
            'receipt_over' => 'nullable|string|max:20', 'receipt_always' => 'nullable|boolean', 'action' => 'required|in:warn,block,escalate', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from']);
        if (isset($d['country'])) $d['country'] = strtoupper($d['country']);
        return response()->json($this->rules->limitView($this->rules->createLimit($r->user(), $d)), 201);
    }
    public function rates(Request $r) { return ExpenseRateTable::orderByDesc('id')->limit(200)->get()->map(fn ($x) => $this->rules->rateView($x)); }
    public function createRate(Request $r) {
        $d = $r->validate(['rate_key' => 'nullable|uuid', 'kind' => 'required_without:rate_key|in:mileage,per_diem', 'name' => 'required_without:rate_key|string|max:150', 'country' => 'nullable|string|size:2|alpha', 'grade_id' => 'nullable|integer',
            'currency' => 'required_without:rate_key|'.self::CURRENCIES, 'unit' => 'required_without:rate_key|in:km,mile,day', 'rate' => 'required|string|max:20', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from']);
        if (isset($d['country'])) $d['country'] = strtoupper($d['country']);
        return response()->json($this->rules->rateView($this->rules->createRate($r->user(), $d)), 201);
    }
    public function fxRates(Request $r) { return $this->fx->list($r->user()); }
    public function createFx(Request $r) {
        $d = $r->validate(['base_currency' => 'required|'.self::CURRENCIES, 'quote_currency' => 'required|'.self::CURRENCIES, 'rate' => 'required|string|max:20', 'effective_on' => 'required|date_format:Y-m-d',
            'source' => 'required|in:'.implode(',', FxService::SOURCES), 'source_reference' => 'nullable|string|max:150']);
        return response()->json($this->fx->view($this->fx->create($r->user(), $d)), 201);
    }
    public function requestOverride(Request $r) {
        $d = $r->validate(['claim_uuid' => 'required|uuid', 'base_currency' => 'required|'.self::CURRENCIES, 'quote_currency' => 'required|'.self::CURRENCIES, 'rate' => 'required|string|max:20',
            'effective_on' => 'required|date_format:Y-m-d', 'source_reference' => 'nullable|string|max:150', 'reason' => 'required|string|min:5|max:1000']);
        return response()->json($this->fx->view($this->fx->requestOverride($r->user(), $d)), 201);
    }
    public function decideFx(Request $r, string $uuid) {
        $d = $r->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required|string|min:3|max:1000']);
        return $this->fx->view($this->fx->decide($r->user(), $uuid, $d['decision'], $d['reason']));
    }
}
