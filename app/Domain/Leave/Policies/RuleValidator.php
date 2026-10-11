<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Leave\Support\Units;
use InvalidArgumentException;

/**
 * Validates the leave rule documents (policy-wide `leave_policy_versions.rules` and per-type `leave_policy_type_rules.rules`).
 * Returns a map of dotted path => message; nothing country-specific is hard-coded: country/entity applicability is data.
 */
final class RuleValidator
{
    public const POLICY_KEYS = ['sandwich', 'approvals', 'notice_days', 'backdate_days', 'future_days', 'cycle', 'restrictions', 'notes'];

    public const TYPE_KEYS = ['unit', 'entitlement', 'accrual', 'eligibility', 'limits', 'carry_forward', 'expiry', 'consumption_order', 'documents', 'statutory',
        'pay_class', 'approvals', 'cycle', 'negative_balance', 'restrictions', 'half_day', 'notice_days', 'backdate_days', 'future_days', 'optional_holidays', 'notes',
        // workstream D: separation / transfer handling is policy data (Transfers\TransferService)
        'transfer', 'encashment', 'separation', 'rehire'];

    public const UNITS = ['day', 'half_day', 'quarter_day', 'hour'];

    public const CARRY_MODES = ['none', 'full', 'capped', 'percentage'];

    public const PAY_CLASSES = ['paid', 'unpaid', 'statutory', 'lop', 'comp_off'];

    /** Canonical names are B's (ConsumptionPlanner::ORDERS); fifo / expiry_first are accepted aliases (see ConsumptionPlanner::normalizeOrder). */
    public const CONSUMPTION = ['earliest_expiring', 'current_first', 'carried_first', 'oldest_first', 'fifo', 'expiry_first'];

    public const TRANSFER_MODES = ['preserve', 'transfer', 'convert', 'expire'];

    /** @return array<string,string> */
    public function policy(array $rules): array
    {
        $errors = [];
        foreach (array_diff(array_keys($rules), self::POLICY_KEYS) as $key) {
            $errors[(string) $key] = 'Unknown policy rule "'.$key.'".';
        }
        foreach (['notice_days', 'backdate_days', 'future_days'] as $key) {
            $this->nonNegativeInt($rules, $key, $errors);
        }
        if (isset($rules['sandwich'])) {
            $s = $rules['sandwich'];
            if (! is_array($s)) {
                $errors['sandwich'] = 'Sandwich rule must be an object.';
            } else {
                foreach (['enabled', 'count_holidays', 'count_weekly_offs'] as $flag) {
                    if (array_key_exists($flag, $s) && ! is_bool($s[$flag])) {
                        $errors['sandwich.'.$flag] = 'Must be true or false.';
                    }
                }
                if (isset($s['apply_to']) && (! is_array($s['apply_to']) || ! array_is_list($s['apply_to']))) {
                    $errors['sandwich.apply_to'] = 'Must be a list of leave type codes.';
                }
            }
        }
        $this->approvals($rules, 'approvals', $errors);
        if (isset($rules['cycle']) && (! is_string($rules['cycle']) || $rules['cycle'] === '')) {
            $errors['cycle'] = 'Cycle must be a cycle code.';
        }

        return $errors;
    }

    /** @return array<string,string> */
    public function type(array $rules): array
    {
        $errors = [];
        foreach (array_diff(array_keys($rules), self::TYPE_KEYS) as $key) {
            $errors[(string) $key] = 'Unknown leave rule "'.$key.'".';
        }
        if (isset($rules['unit']) && ! in_array($rules['unit'], self::UNITS, true)) {
            $errors['unit'] = 'Unit must be one of '.implode(', ', self::UNITS).'.';
        }
        if (isset($rules['pay_class']) && ! in_array($rules['pay_class'], self::PAY_CLASSES, true)) {
            $errors['pay_class'] = 'Pay class must be one of '.implode(', ', self::PAY_CLASSES).'.';
        }
        if (isset($rules['consumption_order']) && ! in_array($rules['consumption_order'], self::CONSUMPTION, true)) {
            $errors['consumption_order'] = 'Consumption order must be one of '.implode(', ', self::CONSUMPTION).'.';
        }
        if (isset($rules['statutory']) && ! is_bool($rules['statutory'])) {
            $errors['statutory'] = 'Must be true or false.';
        }
        foreach (['notice_days', 'backdate_days', 'future_days'] as $key) {
            $this->nonNegativeInt($rules, $key, $errors);
        }
        $this->entitlement($rules['entitlement'] ?? null, $errors);
        $this->eligibility($rules['eligibility'] ?? null, $errors);
        $this->limits($rules['limits'] ?? null, $errors);
        $this->carry($rules['carry_forward'] ?? null, $errors);
        if (isset($rules['negative_balance'])) {
            $this->units($rules['negative_balance'], 'negative_balance', $errors);
        }
        if (isset($rules['documents']) && (! is_array($rules['documents']) || ! array_is_list($rules['documents']))) {
            $errors['documents'] = 'Documents must be a list.';
        }
        $this->approvals($rules, 'approvals', $errors);
        foreach (['transfer', 'encashment', 'separation', 'rehire'] as $key) {
            if (isset($rules[$key]) && ! is_array($rules[$key])) {
                $errors[$key] = ucfirst($key).' must be an object.';
            }
        }
        if (isset($rules['transfer']['mode']) && ! in_array($rules['transfer']['mode'], self::TRANSFER_MODES, true)) {
            $errors['transfer.mode'] = 'Mode must be one of '.implode(', ', self::TRANSFER_MODES).'.';
        }

        return $errors;
    }

    private function entitlement(mixed $e, array &$errors): void
    {
        if ($e === null) {
            return;
        }
        if (! is_array($e)) {
            $errors['entitlement'] = 'Entitlement must be an object.';

            return;
        }
        if (isset($e['basis']) && (! is_string($e['basis']) || $e['basis'] === '')) {
            $errors['entitlement.basis'] = 'Basis must be text such as annual or monthly.';
        }
        if (isset($e['amount'])) {
            $this->units($e['amount'], 'entitlement.amount', $errors);
        }
        if (isset($e['tenure_bands']) && ! is_array($e['tenure_bands'])) {
            $errors['entitlement.tenure_bands'] = 'Tenure bands must be a list.';

            return;
        }
        foreach ($e['tenure_bands'] ?? [] as $i => $band) {
            $from = is_array($band) ? ($band['from_days'] ?? $band['from_months'] ?? null) : null;
            if (! is_array($band) || ! is_int($from) || $from < 0) {
                $errors["entitlement.tenure_bands.$i"] = 'Each band needs a whole number from_days (or from_months) of 0 or more.';
            } elseif (isset($band['amount'])) {
                $this->units($band['amount'], "entitlement.tenure_bands.$i.amount", $errors);
            }
        }
    }

    private function eligibility(mixed $e, array &$errors): void
    {
        if ($e === null) {
            return;
        }
        if (! is_array($e)) {
            $errors['eligibility'] = 'Eligibility must be an object.';

            return;
        }
        foreach (['probation_days', 'min_tenure_days', 'max_tenure_days'] as $key) {
            if (isset($e[$key]) && (! is_int($e[$key]) || $e[$key] < 0)) {
                $errors["eligibility.$key"] = 'Must be a whole number of days, 0 or more.';
            }
        }
        foreach (['requires_confirmation', 'block_during_notice'] as $flag) {
            if (isset($e[$flag]) && ! is_bool($e[$flag])) {
                $errors["eligibility.$flag"] = 'Must be true or false.';
            }
        }
        foreach (['employee_types', 'legal_entities', 'countries', 'worker_types'] as $list) {
            if (isset($e[$list]) && (! is_array($e[$list]) || ! array_is_list($e[$list]))) {
                $errors["eligibility.$list"] = 'Must be a list.';
            }
        }
        foreach ($e['countries'] ?? [] as $c) {
            if (! is_string($c) || ! preg_match('/^[A-Za-z]{2}$/', $c)) {
                $errors['eligibility.countries'] = 'Use two-letter country codes.';
                break;
            }
        }
    }

    private function limits(mixed $l, array &$errors): void
    {
        if ($l === null) {
            return;
        }
        if (! is_array($l)) {
            $errors['limits'] = 'Limits must be an object.';

            return;
        }
        // advance_leave is a flag (borrow against the period entitlement); a number is accepted for older documents.
        if (isset($l['advance_leave']) && ! is_bool($l['advance_leave'])) {
            $this->units($l['advance_leave'], 'limits.advance_leave', $errors);
        }
        foreach (['min_units', 'max_units', 'max_consecutive', 'min_consecutive', 'negative_limit', 'max_balance'] as $key) {
            if (isset($l[$key])) {
                $this->units($l[$key], "limits.$key", $errors);
            }
        }
        foreach ([['min_units', 'max_units'], ['min_consecutive', 'max_consecutive']] as [$min, $max]) {
            if (isset($l[$min], $l[$max]) && ! isset($errors["limits.$min"]) && ! isset($errors["limits.$max"])
                && bccomp(Units::normalize($l[$min]), Units::normalize($l[$max]), 4) > 0) {
                $errors["limits.$max"] = 'Maximum cannot be below the minimum.';
            }
        }
    }

    private function carry(mixed $c, array &$errors): void
    {
        if ($c === null) {
            return;
        }
        if (! is_array($c)) {
            $errors['carry_forward'] = 'Carry forward must be an object.';

            return;
        }
        $mode = $c['mode'] ?? 'none';
        if (! in_array($mode, self::CARRY_MODES, true)) {
            $errors['carry_forward.mode'] = 'Mode must be one of '.implode(', ', self::CARRY_MODES).'.';

            return;
        }
        if ($mode === 'capped') {
            isset($c['cap']) ? $this->units($c['cap'], 'carry_forward.cap', $errors) : $errors['carry_forward.cap'] = 'A capped carry forward needs a cap.';
        }
        if ($mode === 'percentage' && (! isset($c['percent']) || ! is_numeric($c['percent']) || $c['percent'] < 0 || $c['percent'] > 100)) {
            $errors['carry_forward.percent'] = 'Percent must be between 0 and 100.';
        }
        if (isset($c['valid_days']) && (! is_int($c['valid_days']) || $c['valid_days'] < 0)) {
            $errors['carry_forward.valid_days'] = 'Must be a whole number of days.';
        }
    }

    private function approvals(array $rules, string $key, array &$errors): void
    {
        if (! isset($rules[$key])) {
            return;
        }
        $a = $rules[$key];
        if (! is_array($a)) {
            $errors[$key] = 'Approvals must be an object.';

            return;
        }
        if (isset($a['steps']) && (! is_array($a['steps']) || ! array_is_list($a['steps']))) {
            $errors["$key.steps"] = 'Steps must be a list.';
        }
        if (isset($a['escalate_after_hours']) && (! is_int($a['escalate_after_hours']) || $a['escalate_after_hours'] < 0)) {
            $errors["$key.escalate_after_hours"] = 'Must be a whole number of hours.';
        }
    }

    private function nonNegativeInt(array $rules, string $key, array &$errors): void
    {
        if (isset($rules[$key]) && (! is_int($rules[$key]) || $rules[$key] < 0)) {
            $errors[$key] = 'Must be a whole number of days, 0 or more.';
        }
    }

    private function units(mixed $value, string $path, array &$errors): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $errors[$path] = 'Must be a number.';

            return;
        }
        try {
            if (bccomp(Units::strict($value), '0', 4) < 0) {
                $errors[$path] = 'Cannot be negative.';
            }
        } catch (InvalidArgumentException) {
            $errors[$path] = 'Use a number with at most 4 decimal places.';
        }
    }
}
