<?php

namespace App\Http\Controllers\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\Access\FieldAccess;
use App\Domain\People\Models\Employee;
use App\Domain\People\Sections\ExperienceService;
use App\Domain\People\Sections\PersonSection;
use App\Domain\People\Sections\SectionRules;
use App\Domain\Tenancy\TenantClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * /employees/{uuid}/experience. last_salary is encrypted at rest; it is serialized only when FieldAccess
 * ("experience.last_salary") allows (full = amount, masked = placeholder, hidden = keys omitted).
 */
class ExperienceController extends PersonSectionController
{
    private const SALARY_FIELDS = ['last_salary', 'last_salary_currency'];

    protected function service(): PersonSection
    {
        return app(ExperienceService::class);
    }

    protected function field(): string
    {
        return 'experience.records';
    }

    protected function group(): string
    {
        return 'experience';
    }

    protected function rules(bool $create, ?Model $existing): array
    {
        $today = TenantClock::today();

        return [
            'employer' => [...SectionRules::required($create), 'string', 'max:255'],
            'job_title' => [...SectionRules::required($create), 'string', 'max:255'],
            'location' => [...SectionRules::optional(), 'string', 'max:255'],
            'start_date' => [...SectionRules::required($create), 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'end_date' => [...SectionRules::optional(), 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'responsibilities' => [...SectionRules::optional(), 'string', 'max:5000'],
            'last_salary' => [...SectionRules::optional(), 'numeric', 'min:0', 'max:999999999999'],
            'last_salary_currency' => [...SectionRules::optional(), 'regex:/^[A-Za-z]{3}$/'],
            'reason_for_leaving' => [...SectionRules::optional(), 'string', 'max:1000'],
            'document_uuid' => [...SectionRules::optional(), 'uuid'],
        ];
    }

    protected function messages(): array
    {
        return [
            'employer.required' => 'Enter the name of the employer.',
            'job_title.required' => 'Enter the job title.',
            'start_date.required' => 'Enter the date the job started.',
            'start_date.before_or_equal' => 'The start date cannot be in the future.',
            'end_date.before_or_equal' => 'The end date cannot be in the future. Leave it empty for a job that is still ongoing.',
            'start_date.date_format' => 'Enter dates as YYYY-MM-DD.',
            'end_date.date_format' => 'Enter dates as YYYY-MM-DD.',
            'last_salary.numeric' => 'Enter the last salary as a number.',
            'last_salary_currency.regex' => 'Enter the currency as a three-letter code, for example INR.',
        ];
    }

    protected function prepare(Request $r, Employee $employee, array $data): array
    {
        if (array_intersect(self::SALARY_FIELDS, array_keys($data))) {
            // Writing salary details needs full read standing, except for people entering their own history
            // (who see it masked by default).
            $relation = $this->access()->relation($r->user(), $employee);
            $mode = $this->access()->view($r->user(), 'experience.last_salary', $employee, $relation);
            abort_unless(
                $this->access()->edit($r->user(), 'experience.last_salary', $employee, $relation) !== 'none' && ($mode === FieldAccess::FULL || $relation['self']),
                403,
                'You cannot change salary details.'
            );
        }
        if (isset($data['last_salary_currency'])) {
            $data['last_salary_currency'] = strtoupper($data['last_salary_currency']);
        }

        return $data;
    }

    protected function viewer(Request $r, Employee $employee): array
    {
        return ['salary' => $this->access()->view($r->user(), 'experience.last_salary', $employee)];
    }

    protected function meta(Request $r, Employee $employee, Collection $rows): array
    {
        return ['total_experience' => $this->service()->totalExperience($rows)];
    }

    protected function context(Request $r, Employee $employee, Collection $rows): array
    {
        $relation = $this->access()->relation($r->user(), $employee);

        return parent::context($r, $employee, $rows) + [
            'salary' => $this->access()->view($r->user(), 'experience.last_salary', $employee, $relation),
            'reason' => $this->access()->viewClass($r->user(), Classification::EMPLOYEE, $employee, $relation) !== FieldAccess::HIDDEN,
        ];
    }

    protected function present(Model $row, array $ctx): array
    {
        $out = [
            'uuid' => $row->uuid, 'employer' => $row->employer, 'job_title' => $row->job_title, 'location' => $row->location,
            'start_date' => $this->date($row->start_date), 'end_date' => $this->date($row->end_date), 'is_current' => $row->end_date === null,
            'responsibilities' => $row->responsibilities, 'document_uuid' => $ctx['documents'][$row->document_id] ?? null,
        ];
        if ($ctx['reason']) {
            $out['reason_for_leaving'] = $row->reason_for_leaving;
        }
        if ($ctx['salary'] === FieldAccess::FULL) {
            $plain = ExperienceService::decryptSalary($row->getRawOriginal('last_salary'));
            $out['last_salary'] = $plain === null ? null : (float) $plain;
            $out['last_salary_currency'] = $row->last_salary_currency;
        } elseif ($ctx['salary'] === FieldAccess::MASKED) {
            $out['last_salary'] = $row->getRawOriginal('last_salary') ? str_repeat('•', 6) : null;
            $out['last_salary_currency'] = $row->last_salary_currency;
        }
        $out['created_at'] = $this->instant($row->created_at);
        $out['updated_at'] = $this->instant($row->updated_at);

        return $out;
    }
}
