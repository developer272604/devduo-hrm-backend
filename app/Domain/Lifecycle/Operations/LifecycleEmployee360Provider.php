<?php

namespace App\Domain\Lifecycle\Operations;

use App\Domain\Access\DataScope;
use App\Domain\Lifecycle\Models\{LifecycleCase, LifecycleTask, Move};
use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Models\Employee;
use App\Models\User;

/** Facts only: checklist status and move history. Exit interviews and notes are never shown here. */
final class LifecycleEmployee360Provider implements Employee360Provider
{
    public function key(): string
    {
        return 'lifecycle';
    }

    public function sections(User $viewer, Employee $employee, array $relation): array
    {
        if (! $viewer->allows('lifecycle.view') || ! app(DataScope::class)->canAccess($viewer, $employee, 'lifecycle.view')) return [];
        $items = [];
        foreach (LifecycleCase::where('employee_id', $employee->id)->orderByDesc('id')->limit(5)->get() as $c) {
            $total = LifecycleTask::where('case_id', $c->id)->count();
            $done = LifecycleTask::where('case_id', $c->id)->whereIn('status', ['done', 'skipped'])->count();
            $items[] = ['key' => 'case:'.$c->uuid, 'label' => ucfirst($c->kind).' checklist', 'value' => $c->status.' ('.$done.'/'.$total.' tasks)', 'date' => $c->anchor_date->toDateString(), 'source' => 'lifecycle'];
        }
        foreach (Move::where('employee_id', $employee->id)->orderByDesc('id')->limit(5)->get() as $m) {
            $items[] = ['key' => 'move:'.$m->uuid, 'label' => ucfirst(str_replace('_', ' ', $m->kind)), 'value' => $m->status, 'date' => $m->effective_date->toDateString(), 'source' => 'lifecycle'];
        }

        return $items ? [['key' => 'lifecycle', 'title' => 'Lifecycle', 'order' => 80, 'source' => 'lifecycle', 'items' => $items]] : [];
    }
}
