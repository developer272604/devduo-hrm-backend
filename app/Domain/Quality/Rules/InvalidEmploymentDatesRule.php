<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Access\Classification;
use App\Domain\Quality\Finding;

/** Impossible employment dates: joined after separated, probation/contract end before start, original joining after joining. */
final class InvalidEmploymentDatesRule extends PeopleRule
{
    /** label => [column that must not be earlier..., column it is compared to]: first < second is a problem. */
    private const CHECKS = [
        'Joining date is after the last working date' => ['separated_on', 'joined_on'],
        'Probation end is before probation start' => ['probation_end', 'probation_start'],
        'Contract end is before contract start' => ['contract_end', 'contract_start'],
        'Original joining date is after the joining date' => ['joined_on', 'original_joining_date'],
    ];

    public function key(): string
    {
        return 'invalid_employment_dates';
    }

    public function category(): string
    {
        return 'employment';
    }

    public function label(): string
    {
        return 'Invalid employment dates';
    }

    public function scan(int $tenantId): iterable
    {
        $q = $this->employees($tenantId, null)->where('e.status', '!=', 'archived')
            ->select(['e.id as id', 'e.joined_on', 'e.separated_on', 'e.probation_start', 'e.probation_end', 'e.contract_start', 'e.contract_end', 'e.original_joining_date']);
        foreach ($this->stream($q) as $row) {
            $problems = [];
            foreach (self::CHECKS as $label => [$a, $b]) {
                if ($row->{$a} && $row->{$b} && substr((string) $row->{$a}, 0, 10) < substr((string) $row->{$b}, 0, 10)) {
                    $problems[] = $label;
                }
            }
            if ($problems) {
                yield new Finding((int) $row->id, 'emp:'.$row->id, 'Invalid employment dates: '.$this->list($problems), 'Correct the dates on the employment record.', 'action_required',
                    ['employee', (int) $row->id], ['problems' => $problems], Classification::HR_CONFIDENTIAL);
            }
        }
    }
}
