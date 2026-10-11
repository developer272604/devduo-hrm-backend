<?php

namespace App\Domain\People\Insights;

use App\Domain\Access\Classification;
use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Lazy, permission-aware organization chart. It never loads the tree: every call reads ONE level (a page of roots, one page of
 * children, or one page of search hits) with a fixed number of queries - the node rows, one grouped query for the children
 * counts and one query per level of "chain upward" - regardless of company size. Only employees inside the viewer's data scope
 * are nodes; managers outside the scope appear only as names in the upward chain so the chart stays connected.
 * Directory privacy (manager / department / location / photo) is applied per node.
 */
final class OrgChartService
{
    public const MAX_DEPTH = 3;

    private const LEVEL_CAP = 200;

    public function __construct(private DataScope $scope) {}

    /** Default entry: in-scope employees whose manager is missing or outside the scope (company heads, or the viewer's own node). */
    public function roots(User $viewer, ViewerRelations $rel, int $page, int $perPage, int $depth = 1): LengthAwarePaginator
    {
        $q = $this->nodes($viewer)->whereNotExists(function ($s) use ($viewer) {
            $today = TenantClock::today();
            $s->from('employee_reporting_relationships as r2')->join('employees as pm', 'pm.id', '=', 'r2.manager_id')->whereColumn('r2.employee_id', 'e.id')->where('r2.type', 'primary')
                ->where('r2.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('r2.effective_to')->orWhere('r2.effective_to', '>=', $today))
                ->whereNull('pm.deleted_at')->whereIn('pm.status', Employee::WORKING);
            $this->scope->constrain($s, $viewer, 'people.view', 'pm.id');
        })->orderBy('p.display_name')->orderBy('e.id');

        return $this->pageOf($viewer, $rel, $q, $page, $perPage, true, $depth);
    }

    /** One node (the "root" parameter). Null when the employee is not part of the chart for this viewer. */
    public function node(User $viewer, ViewerRelations $rel, Employee $employee, int $depth = 1): ?array
    {
        $row = $this->nodes($viewer)->where('e.id', $employee->id)->first();
        if (! $row) {
            return null;
        }
        $nodes = $this->present($viewer, $rel, [$row], true, $depth);

        return $nodes[0];
    }

    /** True when the employee may be expanded by this viewer: in scope, in the viewer's own upward chain, or the parent of someone in scope. */
    public function expandable(User $viewer, ViewerRelations $rel, Employee $parent): bool
    {
        if ($this->scope->canAccess($viewer, $parent, 'people.view') || $this->scope->isSelf($viewer, $parent)) {
            return true;
        }

        return $this->childrenQuery($viewer, [$parent->id])->exists();
    }

    public function children(User $viewer, ViewerRelations $rel, Employee $parent, int $page, int $perPage): LengthAwarePaginator
    {
        $q = $this->childrenQuery($viewer, [$parent->id])->orderBy('p.display_name')->orderBy('e.id');

        return $this->pageOf($viewer, $rel, $q, $page, $perPage, false, 1);
    }

    /** @param array{q?:?string,department_id?:mixed,location_id?:mixed} $filters */
    public function search(User $viewer, ViewerRelations $rel, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $q = $this->nodes($viewer);
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes(mb_substr(trim($filters['q']), 0, 100), '%_\\').'%';
            $q->where(fn ($w) => $w->where('p.display_name', 'like', $term)->orWhere('p.preferred_name', 'like', $term)->orWhere('des.name', 'like', $term));
        }
        foreach (['department_id' => 'a.department_id', 'location_id' => 'a.location_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $q->whereIn($column, (array) $filters[$key]);
            }
        }
        $q->orderBy('p.display_name')->orderBy('e.id');

        return $this->pageOf($viewer, $rel, $q, $page, $perPage, true, 1);
    }

    /** In-scope working employees with the columns a node needs. */
    private function nodes(User $viewer)
    {
        $today = TenantClock::today();
        $q = DB::table('employees as e')->join('people as p', fn ($j) => $j->on('p.id', '=', 'e.person_id')->whereNull('p.deleted_at'))
            ->leftJoin('employee_assignments as a', function ($j) use ($today) {
                $j->on('a.employee_id', '=', 'e.id')->where('a.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $today));
            })->leftJoin('departments as dep', 'dep.id', '=', 'a.department_id')->leftJoin('designations as des', 'des.id', '=', 'a.designation_id')->leftJoin('locations as loc', 'loc.id', '=', 'a.location_id')
            ->where('e.tenant_id', $viewer->tenant_id)->whereNull('e.deleted_at')->whereIn('e.status', Employee::WORKING)
            ->select(['e.id', 'e.uuid', 'e.status', 'p.display_name', 'p.preferred_name', 'p.photo_path', 'des.name as designation', 'dep.name as department', 'loc.name as location']);
        $this->scope->constrain($q, $viewer, 'people.view', 'e.id');

        return $q;
    }

    /** In-scope direct reports of the given managers (adds manager_id). */
    private function childrenQuery(User $viewer, array $managerIds)
    {
        $today = TenantClock::today();

        return $this->nodes($viewer)->join('employee_reporting_relationships as r', function ($j) use ($today) {
            $j->on('r.employee_id', '=', 'e.id')->where('r.type', '=', 'primary')->where('r.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $today));
        })->whereIn('r.manager_id', $managerIds)->addSelect('r.manager_id');
    }

    private function pageOf(User $viewer, ViewerRelations $rel, $query, int $page, int $perPage, bool $withChain, int $depth): LengthAwarePaginator
    {
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $paginator->setCollection(collect($this->present($viewer, $rel, $paginator->getCollection()->all(), $withChain, $depth)));

        return $paginator;
    }

    /**
     * @param  array<int,object>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function present(User $viewer, ViewerRelations $rel, array $rows, bool $withChain, int $depth): array
    {
        if (! $rows) {
            return [];
        }
        $ids = array_map(fn ($r) => $r->id, $rows);
        $counts = $this->childCounts($viewer, $ids);
        $chains = $withChain ? $this->chains($rel, $ids) : [];
        $out = [];
        foreach ($rows as $row) {
            $node = $this->node1($rel, $row, $counts[$row->id] ?? 0);
            if ($withChain) {
                $node['ancestors'] = $chains[$row->id] ?? [];
            }
            $out[$row->id] = $node;
        }
        if ($depth > 1) {
            $this->expand($viewer, $rel, $out, $depth - 1);
        }

        return array_values(array_map(fn ($n) => $this->strip($n), $out));
    }

    /** @param array<string,mixed> $node */
    private function strip(array $node): array
    {
        unset($node['_id'], $node['_manager_id']);
        if (isset($node['children'])) {
            $node['children'] = array_map(fn ($c) => $this->strip($c), $node['children']);
        }

        return $node;
    }

    private function node1(ViewerRelations $rel, object $row, int $children): array
    {
        $relation = $rel->relation($row->id);
        $visible = fn (?string $key) => $rel->visibility(Classification::PUBLIC_INTERNAL, $relation, $key) !== FieldAccess::HIDDEN;

        return ['uuid' => $row->uuid, 'name' => $row->display_name, 'status' => $row->status, 'designation' => $row->designation, 'department' => $visible('department') ? $row->department : null,
            'location' => $visible('location') ? $row->location : null, 'has_photo' => $visible('photo') && (bool) $row->photo_path, 'is_self' => $relation['self'],
            'children_count' => $children, 'has_children' => $children > 0, 'in_scope' => true, '_id' => $row->id, '_manager_id' => $row->manager_id ?? null];
    }

    /** Loads one more level for the given nodes (indexed by employee id), capped per level. @param array<int,array<string,mixed>> $nodes */
    private function expand(User $viewer, ViewerRelations $rel, array &$nodes, int $levels): void
    {
        $parents = array_keys(array_filter($nodes, fn ($n) => $n['has_children']));
        if (! $parents) {
            return;
        }
        $rows = $this->childrenQuery($viewer, $parents)->orderBy('p.display_name')->limit(self::LEVEL_CAP + 1)->get()->all();
        $more = count($rows) > self::LEVEL_CAP;
        $rows = array_slice($rows, 0, self::LEVEL_CAP);
        $childNodes = [];
        $counts = $this->childCounts($viewer, array_map(fn ($r) => $r->id, $rows));
        foreach ($rows as $row) {
            $childNodes[$row->id] = $this->node1($rel, $row, $counts[$row->id] ?? 0);
        }
        if ($levels > 1) {
            $this->expand($viewer, $rel, $childNodes, $levels - 1);
        }
        foreach ($nodes as $id => &$node) {
            $node['children'] = [];
            $node['children_truncated'] = $more;
        }
        unset($node);
        foreach ($childNodes as $id => $child) {
            $parentId = $child['_manager_id'];
            if (isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = $child;
            }
        }
    }

    /** @return array<int,int> manager id => number of in-scope working direct reports (one grouped query). */
    private function childCounts(User $viewer, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $today = TenantClock::today();
        $q = DB::table('employee_reporting_relationships as r')->join('employees as e', 'e.id', '=', 'r.employee_id')->where('r.tenant_id', $viewer->tenant_id)->where('r.type', 'primary')
            ->where('r.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $today))->whereIn('r.manager_id', $ids)
            ->whereNull('e.deleted_at')->whereIn('e.status', Employee::WORKING);
        $this->scope->constrain($q, $viewer, 'people.view', 'e.id');

        return $q->groupBy('r.manager_id')->selectRaw('r.manager_id as manager_id, COUNT(*) as total')->pluck('total', 'manager_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Upward chains (top first), names only, one query per level. Hidden entirely when directory privacy hides the manager from the viewer.
     *
     * @return array<int,array<int,array{uuid:string,name:string}>>
     */
    private function chains(ViewerRelations $rel, array $ids): array
    {
        $today = TenantClock::today();
        $allowed = array_values(array_filter($ids, fn ($id) => $rel->visibility(Classification::PUBLIC_INTERNAL, $rel->relation($id), 'manager') !== FieldAccess::HIDDEN));
        $cursor = array_combine($allowed, $allowed);
        $chains = array_fill_keys($allowed, []);
        $seen = array_map(fn ($id) => [$id => true], array_combine($allowed, $allowed));
        for ($level = 0; $level < 12 && $cursor; $level++) {
            $rows = DB::table('employee_reporting_relationships as r')->join('employees as m', 'm.id', '=', 'r.manager_id')->join('people as mp', 'mp.id', '=', 'm.person_id')
                ->where('r.type', 'primary')->where('r.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $today))
                ->whereIn('r.employee_id', array_values(array_unique($cursor)))->whereNull('m.deleted_at')->get(['r.employee_id', 'm.id as manager_id', 'm.uuid', 'mp.display_name'])->keyBy('employee_id');
            $next = [];
            foreach ($cursor as $start => $at) {
                $m = $rows[$at] ?? null;
                if (! $m || isset($seen[$start][$m->manager_id])) {
                    continue;
                }
                $seen[$start][$m->manager_id] = true;
                array_unshift($chains[$start], ['uuid' => $m->uuid, 'name' => $m->display_name]);
                $next[$start] = $m->manager_id;
            }
            $cursor = $next;
        }

        return $chains;
    }
}
