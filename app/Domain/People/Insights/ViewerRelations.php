<?php

namespace App\Domain\People\Insights;

use App\Domain\Access\Classification;
use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\Access\FieldRegistry;
use App\Domain\Access\PeopleSettings;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\ReportingService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Per-request cache of "how does this viewer relate to employees": own employments and the managerial subtree
 * (computed once), plus memoised FieldAccess decisions. Bulk insights (quality, completeness, dashboard, org chart)
 * need a visibility decision per employee and per field; asking FieldAccess row by row would run several permission
 * queries each time. Decisions only depend on (class, self, manager), so they are memoised on that key and still go
 * through FieldAccess::viewClass - there is no second copy of the rules.
 */
final class ViewerRelations
{
    /** @var array<int,int>|null */
    private ?array $own = null;

    /** @var array<int,true>|null */
    private ?array $subordinates = null;

    /** @var array<string,string> */
    private array $memo = [];

    /** @var array<string,bool> */
    private array $permissions = [];

    private FieldAccess $access;

    public function __construct(public readonly User $viewer)
    {
        $this->access = app(FieldAccess::class);
    }

    public static function for(User $viewer): self
    {
        return new self($viewer);
    }

    public function allows(string $permission): bool
    {
        return $this->permissions[$permission] ??= $this->viewer->allows($permission);
    }

    /** @return array<int,int> */
    public function ownIds(): array
    {
        return $this->own ??= app(DataScope::class)->ownEmployeeIds($this->viewer);
    }

    /** @return array<int,true> employee id => true for everyone below the viewer's own employments (primary line). */
    public function subordinateIds(): array
    {
        if ($this->subordinates !== null) {
            return $this->subordinates;
        }
        $this->subordinates = [];
        $own = $this->ownIds();
        if ($own) {
            $reporting = app(ReportingService::class);
            foreach (Employee::query()->whereIn('id', $own)->get() as $manager) {
                foreach ($reporting->getAllSubordinates($manager) as $id) {
                    $this->subordinates[$id] = true;
                }
            }
            foreach ($own as $id) {
                unset($this->subordinates[$id]);
            }
        }

        return $this->subordinates;
    }

    /** @return array{self:bool,manager:bool} */
    public function relation(int $employeeId): array
    {
        $self = in_array($employeeId, $this->ownIds(), true);

        return ['self' => $self, 'manager' => ! $self && isset($this->subordinateIds()[$employeeId])];
    }

    public function isHr(): bool
    {
        return $this->allows('people.personal.view');
    }

    /** FieldAccess::FULL | MASKED | HIDDEN for data of a classification about an employee. */
    public function visibility(string $class, array $relation, ?string $privacyKey = null): string
    {
        $key = $class.'|'.(int) $relation['self'].(int) $relation['manager'].'|'.$privacyKey;

        return $this->memo[$key] ??= $this->access->viewClass($this->viewer, $class, null, $relation, $privacyKey);
    }

    public function canSeeClass(string $class, int $employeeId, ?string $privacyKey = null): bool
    {
        return $this->visibility($class, $this->relation($employeeId), $privacyKey) !== FieldAccess::HIDDEN;
    }

    /** Whether the viewer may see a registry field of an employee (uses tenant classification overrides). */
    public function canSeeField(string $field, int $employeeId): bool
    {
        return $this->canSeeClass(PeopleSettings::classification($field), $employeeId, FieldRegistry::privacyKey($field));
    }

    /**
     * Translates a classification into a row condition on employee ids:
     * true = every employee in scope, false = nobody, array = only these employee ids (self and/or own subtree).
     *
     * @return bool|array<int,int>
     */
    public function classCondition(string $class, ?string $privacyKey = null): bool|array
    {
        $none = ['self' => false, 'manager' => false];
        if ($this->visibility($class, $none, $privacyKey) !== FieldAccess::HIDDEN) {
            return true;
        }
        $ids = [];
        if ($this->visibility($class, ['self' => true, 'manager' => false], $privacyKey) !== FieldAccess::HIDDEN) {
            $ids = $this->ownIds();
        }
        if ($this->visibility($class, ['self' => false, 'manager' => true], $privacyKey) !== FieldAccess::HIDDEN) {
            $ids = array_merge($ids, array_keys($this->subordinateIds()));
        }

        return $ids ? array_values(array_unique($ids)) : false;
    }

    /** Restricts a query over an employee-id column to employees whose data of this class the viewer may see. */
    public function constrainClass(EloquentBuilder|Builder $query, string $class, string $employeeColumn, ?string $privacyKey = null): EloquentBuilder|Builder
    {
        $condition = $this->classCondition($class, $privacyKey);
        if ($condition === true) {
            return $query;
        }
        $condition === false || $condition === [] ? $query->whereRaw('1 = 0') : $query->whereIn($employeeColumn, $condition);

        return $query;
    }

    /** Same, for a registry field key (honours tenant classification overrides and directory privacy). */
    public function constrainField(EloquentBuilder|Builder $query, string $field, string $employeeColumn): EloquentBuilder|Builder
    {
        return $this->constrainClass($query, PeopleSettings::classification($field), $employeeColumn, FieldRegistry::privacyKey($field));
    }

    /** Adds OR-ed per-row conditions for a column that stores a classification (findings). Unknown classes stay hidden. */
    public function constrainStoredClass(EloquentBuilder|Builder $query, string $classColumn, string $employeeColumn): EloquentBuilder|Builder
    {
        $query->where(function ($w) use ($classColumn, $employeeColumn) {
            $any = false;
            foreach (Classification::ALL as $class) {
                $condition = $this->classCondition($class);
                if ($condition === false) {
                    continue;
                }
                $any = true;
                if ($condition === true) {
                    $w->orWhere($classColumn, $class);
                } else {
                    $w->orWhere(fn ($r) => $r->where($classColumn, $class)->whereIn($employeeColumn, $condition));
                }
            }
            if (! $any) {
                $w->whereRaw('1 = 0');
            }
        });

        return $query;
    }
}
