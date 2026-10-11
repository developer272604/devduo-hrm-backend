<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeExperience;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Previous employment. last_salary is stored encrypted (Crypt) and is only ever serialized after a FieldAccess
 * decision by the controller; the plaintext never reaches the audit trail or the timeline.
 */
class ExperienceService extends PersonSection
{
    protected function modelClass(): string
    {
        return EmployeeExperience::class;
    }

    protected function label(): string
    {
        return 'Work experience';
    }

    protected function eventStem(): string
    {
        return 'Experience';
    }

    protected function classification(): string
    {
        return Classification::PUBLIC_INTERNAL;
    }

    protected function hiddenFields(): array
    {
        return ['last_salary', 'last_salary_currency', 'reason_for_leaving'];
    }

    protected function datePairs(): array
    {
        return [['start_date', 'end_date', 'The end date cannot be before the start date.']];
    }

    protected function descriptor(Model $row): ?string
    {
        return $row->job_title.', '.$row->employer;
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderByRaw('end_date IS NULL DESC')->orderByDesc('start_date')->orderByDesc('id');
    }

    protected function creating(Employee $employee, array $data): array
    {
        return $this->encrypt($data);
    }

    protected function updating(Employee $employee, Model $row, array $data): array
    {
        return $this->encrypt($data);
    }

    private function encrypt(array $data): array
    {
        if (array_key_exists('last_salary', $data)) {
            $data['last_salary'] = $data['last_salary'] === null || $data['last_salary'] === '' ? null : Crypt::encryptString((string) $data['last_salary']);
            if ($data['last_salary'] === null) {
                $data['last_salary_currency'] = null;
            }
        }

        return $data;
    }

    public static function decryptSalary(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Total experience across the records with overlapping (and back-to-back) periods merged, so two jobs held in
     * parallel count once. A record without an end date runs until today. End dates are inclusive.
     *
     * @param  iterable<EmployeeExperience>  $rows
     * @return array{years:int,months:int}
     */
    public function totalExperience(iterable $rows): array
    {
        $today = CarbonImmutable::parse($this->today());
        $periods = [];
        foreach ($rows as $row) {
            $start = CarbonImmutable::parse($row->start_date->format('Y-m-d'));
            $end = $row->end_date ? CarbonImmutable::parse($row->end_date->format('Y-m-d')) : $today;
            if ($end >= $start) {
                $periods[] = [$start, $end];
            }
        }
        usort($periods, fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($periods as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]->addDay()) {
                if ($end > $merged[$last][1]) {
                    $merged[$last][1] = $end;
                }
            } else {
                $merged[] = [$start, $end];
            }
        }

        $months = 0;
        $days = 0;
        foreach ($merged as [$start, $end]) {
            $span = $start->diff($end->addDay(), true);
            $months += ((int) $span->y) * 12 + (int) $span->m;
            $days += (int) $span->d;
        }
        $months += intdiv($days, 30);

        return ['years' => intdiv($months, 12), 'months' => $months % 12];
    }
}
