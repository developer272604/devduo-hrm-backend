<?php

namespace App\Domain\Intelligence;

use App\Domain\Access\DataScope;
use App\Domain\Intelligence\Models\Finding;
use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Models\Employee;
use App\Models\User;

/** Employee 360 "Intelligence" panel: only the VIEWER's own open findings that name this employee, and only if the employee is in the viewer's scope. Facts, no scores. */
final class IntelligenceEmployee360Provider implements Employee360Provider
{
    public function key(): string
    {
        return 'intelligence';
    }

    public function sections(User $viewer, Employee $employee, array $relation): array
    {
        if (! $viewer->allows('intelligence.view') || ! app(DataScope::class)->canAccess($viewer, $employee, 'intelligence.view')) {
            return [];
        }
        $items = [];
        foreach (Finding::where('audience_user_id', $viewer->id)->whereIn('status', ['open', 'acknowledged'])->get() as $f) {
            foreach ($f->evidence['sample'] ?? [] as $s) {
                if (($s['employee_uuid'] ?? null) === $employee->uuid) {
                    $items[] = ['key' => 'finding.'.$f->uuid, 'label' => $f->title, 'value' => $f->severity.' / '.$f->status, 'href' => '/intelligence', 'source' => 'intelligence'];
                    break;
                }
            }
        }
        if (! $items) {
            return []; // omit empty sections (Employee 360 rule); the time story stays reachable from the Intelligence Center
        }
        $items[] = ['key' => 'time_story', 'label' => 'Time story', 'value' => 'Dated facts for this employment', 'href' => '/intelligence?story='.$employee->uuid, 'source' => 'intelligence'];

        return [['key' => 'intelligence', 'title' => 'Intelligence', 'order' => 95, 'source' => 'intelligence', 'items' => $items]];
    }
}
