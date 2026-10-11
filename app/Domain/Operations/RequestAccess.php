<?php
namespace App\Domain\Operations;
use App\Domain\Access\DataScope;
use App\Domain\People\Models\Employee;
use App\Domain\Leave\Accrual\Actors;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class RequestAccess {
    public function __construct(private DataScope $scope, private TaskService $tasks) {}
    public function visible($query, User $user, string $module) {
        return $query->where(function ($q) use ($user, $module) {
            $q->whereIn('employee_id', $user->allows($module.'.self') ? $this->scope->ownEmployeeIds($user) : []);
            foreach ([$module.'.approve', $module.'.manage', $module.'.payments.confirm'] as $permission) {
                if ($user->allows($permission)) $q->orWhere(fn ($allowed) => $allowed->where('status', '!=', 'draft')->whereIn('employee_id', $this->scope->constrain(Employee::query()->select('id'), $user, $permission)));
            }
        });
    }
    public function ownEmployee(User $user, string $module): int {
        abort_unless($user->allows($module.'.self'), 403);
        return Employee::whereIn('id', $this->scope->ownEmployeeIds($user))->whereIn('status', Employee::WORKING)->value('id') ?? abort(403, 'A working employee profile is required.');
    }
    public function own(User $user, $record): bool { return $record->created_by === $user->id || $this->scope->isSelf($user, $record->employee_id); }
    public function canReview(User $user, $record, string $module): bool {
        return $user->allows($module.'.approve') && ! $this->own($user, $record) && $this->scope->canAccess($user, $record->employee_id, $module.'.approve');
    }
    public function tenantManager(User $user, string $permission): void { abort_unless($user->allows($permission) && $this->scope->hasTenantWide($user, $permission), 403); }
    public function reviewTasks($record, string $module): void {
        $count = 0;
        foreach (User::whereIn('id', Actors::usersWithPermission($module.'.approve', [$record->created_by]))->get() as $reviewer) {
            if (! $this->canReview($reviewer, $record, $module)) continue;
            $this->tasks->createOnce(['assignee_id' => $reviewer->id, 'title' => 'Review a '.($module === 'expenses' ? 'new expense claim' : 'travel request'),
                'source_module' => $module, 'source_entity' => 'request:'.$record->uuid, 'action' => '/'.$module.'/'.$record->uuid, 'status' => 'open', 'priority' => 'normal']);
            $count++;
        }
        if (! $count) throw ValidationException::withMessages(['reviewer' => 'No independent reviewer has access to your employee profile. Ask an administrator to configure an approval role.']);
    }
    public function completeTasks($record, string $module): void { $this->tasks->completeBySource($module, 'request:'.$record->uuid); }
}
