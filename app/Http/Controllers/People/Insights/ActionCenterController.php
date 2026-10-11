<?php

namespace App\Http\Controllers\People\Insights;

use App\Domain\Audit\Audit;
use App\Domain\Insights\ActionCenter;
use App\Domain\People\Insights\PeopleInsightProvider;
use App\Domain\People\Insights\ReminderService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

class ActionCenterController extends PeopleController
{
    public function index(Request $r, ActionCenter $center)
    {
        $r->validate(['domain' => 'nullable|string|max:40']);

        return ['data' => $center->for($r->user(), $r->input('domain'))];
    }

    /** Everyone behind one People insight (JSON page, or CSV with ?format=csv for viewers who may export). */
    public function employees(Request $r, PeopleInsightProvider $provider, string $key)
    {
        $rows = collect($provider->affected($r->user(), $key))->map(fn ($x) => ['employee_uuid' => $x->uuid, 'name' => $x->name, 'detail' => $x->detail]);
        if ($r->input('format') === 'csv') {
            abort_unless($r->user()->allows('people.export'), 403, 'You do not have access to export this list.');
            Audit::record('insight_exported', 'people', 'insight:'.$key, ['rows' => $rows->count()]);
            $csv = fopen('php://temp', 'w+');
            fputcsv($csv, ['Employee', 'Detail']);
            foreach ($rows as $row) {
                fputcsv($csv, [$this->safe($row['name']), $this->safe($row['detail'])]);
            }
            rewind($csv);

            return response(stream_get_contents($csv), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.str_replace('.', '-', $key).'.csv"']);
        }
        $per = $this->perPage($r);
        $page = max(1, (int) $r->input('page', 1));

        return ['key' => $key, 'total' => $rows->count(), 'per_page' => $per, 'current_page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / $per)), 'data' => $rows->forPage($page, $per)->values()->all()];
    }

    public function remind(Request $r, ReminderService $reminders, PeopleInsightProvider $provider)
    {
        $d = $r->validate(['topic' => 'required|string|in:'.implode(',', array_keys(ReminderService::TOPICS)), 'employee_uuids' => 'nullable|array|max:'.ReminderService::MAX_EMPLOYEES,
            'employee_uuids.*' => 'string|max:40', 'insight' => 'nullable|string|max:60']);
        $uuids = $d['employee_uuids'] ?? [];
        if (! $uuids && ! empty($d['insight'])) {
            $uuids = array_map(fn ($x) => $x->uuid, array_slice($provider->affected($r->user(), $d['insight']), 0, ReminderService::MAX_EMPLOYEES));
        }
        abort_if(! $uuids, 422, 'Choose at least one employee.');

        return $reminders->remind($r->user(), $uuids, $d['topic']);
    }

    public function task(Request $r, ReminderService $reminders)
    {
        $d = $r->validate(['insight' => 'required|string|max:60', 'assignee_id' => 'nullable|integer']);

        return response()->json($reminders->assignTask($r->user(), $d['insight'], (int) ($d['assignee_id'] ?? $r->user()->id)), 201);
    }

    /** Neutralise spreadsheet formulas in exported text. */
    private function safe(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
