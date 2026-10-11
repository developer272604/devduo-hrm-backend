<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Access\Classification;
use App\Domain\Access\PeopleSettings;
use App\Domain\Quality\Finding;

/** Current employees without a primary manager when settings.people.manager_required (default true). Reported as info when 3 or fewer (top of hierarchy). */
final class MissingManagerRule extends PeopleRule
{
    public function key(): string
    {
        return 'missing_manager';
    }

    public function category(): string
    {
        return 'reporting';
    }

    public function label(): string
    {
        return 'Employees without a manager';
    }

    public function scan(int $tenantId): iterable
    {
        if (! PeopleSettings::get('manager_required', true)) {
            return;
        }
        $today = $this->today();
        $ids = $this->employees($tenantId, self::CURRENT)
            ->whereNotExists(function ($s) use ($today) {
                $s->from('employee_reporting_relationships as r')->whereColumn('r.employee_id', 'e.id')->where('r.type', 'primary')->where('r.effective_from', '<=', $today)
                    ->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $today));
            })->orderBy('e.id')->pluck('e.id')->all();
        $severity = count($ids) <= 3 ? 'info' : 'warning';
        foreach ($ids as $id) {
            yield new Finding((int) $id, 'emp:'.$id, 'No reporting manager', 'This employee has no primary reporting manager. If they head the organization this is expected; otherwise assign a manager.', $severity,
                ['employee', (int) $id], ['total_without_manager' => count($ids)], Classification::HR_CONFIDENTIAL);
        }
    }
}
