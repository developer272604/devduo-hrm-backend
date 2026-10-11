<?php

namespace App\Http\Controllers\People\Insights;

use App\Domain\People\Insights\OrgChartService;
use App\Domain\People\Insights\PeopleDashboard;
use App\Domain\People\Insights\ViewerRelations;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

class OrgChartController extends PeopleController
{
    public function index(Request $r, OrgChartService $chart)
    {
        $f = $r->validate(['root' => 'nullable|string|max:40', 'depth' => 'nullable|integer|min:1|max:'.OrgChartService::MAX_DEPTH, 'department_id' => 'nullable|integer', 'location_id' => 'nullable|integer',
            'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer']);
        $rel = ViewerRelations::for($r->user());
        $page = (int) ($f['page'] ?? 1);
        $per = min(50, $this->perPage($r, 20));
        if (! empty($f['q']) || ! empty($f['department_id']) || ! empty($f['location_id'])) {
            return $this->wrap('filtered', $chart->search($r->user(), $rel, $f, $page, $per));
        }
        if (! empty($f['root'])) {
            $employee = Employee::query()->where('uuid', $f['root'])->firstOrFail();
            $node = $chart->node($r->user(), $rel, $employee, (int) ($f['depth'] ?? 1));
            abort_unless($node, 404);

            return ['mode' => 'root', 'data' => [$node]];
        }

        return $this->wrap('tree', $chart->roots($r->user(), $rel, $page, $per, (int) ($f['depth'] ?? 1)));
    }

    public function children(Request $r, OrgChartService $chart, string $uuid)
    {
        $parent = Employee::query()->where('uuid', $uuid)->firstOrFail();
        $rel = ViewerRelations::for($r->user());
        abort_unless($chart->expandable($r->user(), $rel, $parent), 404);
        $r->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer']);

        return $this->wrap('children', $chart->children($r->user(), $rel, $parent, (int) $r->input('page', 1), min(50, $this->perPage($r, 20))));
    }

    public function search(Request $r, OrgChartService $chart)
    {
        $f = $r->validate(['q' => 'required|string|min:2|max:100', 'department_id' => 'nullable|integer', 'location_id' => 'nullable|integer', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer']);

        return $this->wrap('search', $chart->search($r->user(), ViewerRelations::for($r->user()), $f, (int) ($f['page'] ?? 1), min(50, $this->perPage($r, 20))));
    }

    public function dashboard(Request $r, PeopleDashboard $dashboard)
    {
        return $dashboard->build($r->user());
    }

    private function wrap(string $mode, $paginator): array
    {
        return ['mode' => $mode] + $paginator->toArray();
    }
}
