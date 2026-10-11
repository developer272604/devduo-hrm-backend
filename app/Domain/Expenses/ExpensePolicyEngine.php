<?php
namespace App\Domain\Expenses;

use App\Domain\Expenses\Models\{ExpenseClaim, ExpenseViolation};
use App\Domain\Operations\Money;
use App\Domain\Travel\Models\TravelRequest;

/** Evaluates effective-dated limit and receipt rules against a claim's lines. Pure with respect to claim data: it returns violations; persisting is explicit. */
final class ExpensePolicyEngine {
    public function __construct(private ExpenseRuleService $rules) {}

    /** @return array<int,array{line_id:?int,rule_id:?int,code:string,severity:string,message:string,details:array}> */
    public function evaluate(ExpenseClaim $claim): array {
        $claim->loadMissing('lines'); $ctx = ['country' => $claim->country, 'legal_entity_id' => $claim->legal_entity_id, 'grade_id' => $claim->grade_id];
        $default = $claim->policy_snapshot['category'] ?? ''; $out = []; $byRule = [];
        foreach ($claim->lines as $line) {
            $category = $line->category ?: $default;
            $rule = $this->rules->limitFor($category, $line->spent_on->toDateString(), $claim->currency, $ctx);
            if (! $rule) continue;
            $fmt = fn (int $m) => $claim->currency.' '.Money::format($m, $claim->minor_units);
            if ($rule->per_line_limit_minor !== null && $line->amount_minor > $rule->per_line_limit_minor) $out[] = $this->v($line->id, $rule, 'line_limit_exceeded', $category.' line of '.$fmt($line->amount_minor).' exceeds the limit of '.$fmt($rule->per_line_limit_minor).'.', ['limit' => Money::format($rule->per_line_limit_minor, $claim->minor_units)]);
            if ($rule->receipt_always || ($rule->receipt_over_minor !== null && $line->amount_minor >= $rule->receipt_over_minor)) {
                if (! $line->receipt_id) $out[] = $this->v($line->id, $rule, 'receipt_missing', 'A receipt is required for this '.$category.' line.', []);
            }
            $byRule[$rule->id]['rule'] = $rule; $byRule[$rule->id]['total'] = ($byRule[$rule->id]['total'] ?? 0) + $line->amount_minor; $byRule[$rule->id]['category'] = $category;
        }
        foreach ($byRule as $row) {
            $rule = $row['rule'];
            if ($rule->category_total_limit_minor !== null && $row['total'] > $rule->category_total_limit_minor) $out[] = $this->v(null, $rule, 'category_limit_exceeded', $row['category'].' total of '.$claim->currency.' '.Money::format($row['total'], $claim->minor_units).' exceeds the limit of '.$claim->currency.' '.Money::format($rule->category_total_limit_minor, $claim->minor_units).'.', ['limit' => Money::format($rule->category_total_limit_minor, $claim->minor_units)]);
        }
        if ($claim->travel_request_id && ($trip = TravelRequest::find($claim->travel_request_id))) {
            foreach ($claim->lines as $line) {
                $d = $line->spent_on->toDateString();
                if ($d < $trip->starts_on->toDateString() || $d > $trip->ends_on->toDateString()) $out[] = ['line_id' => $line->id, 'rule_id' => null, 'code' => 'outside_trip_dates', 'severity' => 'warn', 'message' => 'An expense date falls outside the approved trip dates.', 'details' => []];
            }
        }
        return $out;
    }
    private function v(?int $lineId, $rule, string $code, string $message, array $details): array {
        return ['line_id' => $lineId, 'rule_id' => $rule->id, 'code' => $code, 'severity' => $rule->action, 'message' => $message, 'details' => $details + ['rule' => $rule->name, 'rule_version' => $rule->version]];
    }
    public function persist(ExpenseClaim $claim, array $violations): void {
        ExpenseViolation::where('claim_id', $claim->id)->delete();
        foreach ($violations as $v) ExpenseViolation::create(['claim_id' => $claim->id, 'line_id' => $v['line_id'], 'rule_id' => $v['rule_id'], 'code' => $v['code'], 'severity' => $v['severity'], 'message' => $v['message'], 'details' => $v['details']]);
    }
}
