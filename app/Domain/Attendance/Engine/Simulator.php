<?php

namespace App\Domain\Attendance\Engine;

use App\Domain\Attendance\Support\AttendanceStatus;
use App\Domain\Attendance\Support\PolicyRules;

/**
 * Policy simulator: runs the SAME input with the current rules and with changed rules and reports the difference.
 * Pure: it only calls the calculator; it never reads or writes storage and cannot modify real attendance.
 */
final class Simulator
{
    /** Result fields compared (scalar), in display order. */
    public const FIELDS = ['status', 'confidence', 'required_minutes', 'gross_minutes', 'break_minutes', 'net_minutes', 'late_minutes', 'early_exit_minutes', 'overtime_minutes', 'missing_punch', 'work_mode'];

    /**
     * @param  array<string,mixed>  $overrideRules  partial rules merged over $current->rules (PolicyRules::merge)
     * @return array{changed:bool,differences:array<int,array{field:string,before:mixed,after:mixed}>,before:array,after:array}
     */
    public static function compare(CalculationInput $current, array $overrideRules): array
    {
        $proposed = new CalculationInput(
            schedule: $current->schedule,
            rules: PolicyRules::merge($current->rules, $overrideRules),
            punches: $current->punches,
            now: $current->now,
            onDuty: $current->onDuty,
            leave: $current->leave,
            monthToDate: $current->monthToDate,
            approvedOvertimeMinutes: $current->approvedOvertimeMinutes,
            remoteApproved: $current->remoteApproved,
            employeeTypeOvertimeEligible: $current->employeeTypeOvertimeEligible,
            policyLabel: $current->policyLabel,
            policyVersionId: $current->policyVersionId,
            previousDayEndUtc: $current->previousDayEndUtc,
            compliance: $current->compliance,
        );
        $before = AttendanceCalculator::calculate($current)->toArray();
        $after = AttendanceCalculator::calculate($proposed)->toArray();

        $diffs = [];
        foreach (self::FIELDS as $f) {
            if ($before[$f] !== $after[$f]) {
                $diffs[] = ['field' => $f, 'before' => $before[$f], 'after' => $after[$f]];
            }
        }
        $bt = self::exceptionTypes($before);
        $at = self::exceptionTypes($after);
        if ($bt !== $at) {
            $diffs[] = ['field' => 'exceptions', 'before' => $bt, 'after' => $at];
        }

        return ['changed' => $diffs !== [], 'differences' => $diffs, 'before' => $before, 'after' => $after];
    }

    /**
     * @param  array<int,array<string,mixed>>  $comparisons  results of compare() (optionally with extra keys, ignored)
     * @return array{total:int,changed:int,unchanged:int,by_field:array<string,int>,status_transitions:array<int,array{from:string,to:string,count:int,message:string}>,messages:array<int,string>}
     */
    public static function summarize(array $comparisons): array
    {
        $byField = [];
        $transitions = [];
        $changed = 0;
        foreach ($comparisons as $c) {
            if (empty($c['changed'])) {
                continue;
            }
            $changed++;
            foreach ($c['differences'] as $d) {
                $byField[$d['field']] = ($byField[$d['field']] ?? 0) + 1;
                if ($d['field'] === 'status') {
                    $key = $d['before']."\0".$d['after'];
                    $transitions[$key] = ($transitions[$key] ?? 0) + 1;
                }
            }
        }
        ksort($byField);
        $list = [];
        foreach ($transitions as $key => $count) {
            [$from, $to] = explode("\0", $key);
            $list[] = ['from' => $from, 'to' => $to, 'count' => $count, 'message' => $count.' record'.($count === 1 ? '' : 's').' would change from '.AttendanceStatus::label($from).' to '.AttendanceStatus::label($to)];
        }
        usort($list, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['from'].$a['to'], $b['from'].$b['to']));

        $messages = array_column($list, 'message');
        $total = count($comparisons);
        $messages[] = $changed.' of '.$total.' record'.($total === 1 ? '' : 's').' would change.';

        return ['total' => $total, 'changed' => $changed, 'unchanged' => $total - $changed, 'by_field' => $byField, 'status_transitions' => $list, 'messages' => $messages];
    }

    private static function exceptionTypes(array $result): array
    {
        $types = array_values(array_unique(array_column($result['exceptions'], 'type')));
        sort($types);

        return $types;
    }
}
