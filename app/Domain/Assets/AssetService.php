<?php
namespace App\Domain\Assets;

use App\Domain\Access\DataScope;
use App\Domain\Assets\Models\{Asset, AssetMovement};
use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\Leave\Accrual\Actors;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssetService {
    public function __construct(private DataScope $scope, private TaskService $tasks) {}

    public function manages(User $user): bool { return $user->allows('assets.manage') && $this->scope->hasTenantWide($user, 'assets.manage'); }

    public function visible(User $user) {
        $q = Asset::with('employee.person');
        return $this->manages($user) ? $q : $q->whereIn('employee_id', $this->scope->ownEmployeeIds($user));
    }

    public function present(Asset $asset, User $user, bool $detail = false): array {
        $manager = $this->manages($user);
        $asset->loadMissing('employee.person');
        $result = ['uuid' => $asset->uuid, 'identifier' => $asset->identifier, 'name' => $asset->name,
            'category' => $asset->category, 'serial_number' => $asset->serial_number, 'model' => $asset->model,
            'location' => $asset->location, 'status' => $asset->status, 'condition' => $asset->condition, 'version' => $asset->version,
            'employee_uuid' => $asset->employee?->uuid, 'employee_name' => $asset->employee ? trim($asset->employee->person?->legal_first_name.' '.$asset->employee->person?->legal_last_name) : null,
            'acknowledged_at' => $asset->acknowledged_at?->toIso8601String(),
            'can_acknowledge' => $asset->status === 'assigned' && ! $asset->acknowledged_at && in_array($asset->employee_id, $this->scope->ownEmployeeIds($user))];
        if ($manager) $result += ['purchase_reference' => $asset->purchase_reference, 'warranty_until' => $asset->warranty_until?->toDateString()];
        if ($detail) {
            $events = $asset->movements();
            if (! $manager) $events->where(fn ($q) => $q->whereIn('from_employee_id', $this->scope->ownEmployeeIds($user))->orWhereIn('to_employee_id', $this->scope->ownEmployeeIds($user)));
            $result['movements'] = $events->limit(100)->get()->map(fn ($e) => ['uuid' => $e->uuid, 'action' => $e->action, 'from_status' => $e->from_status, 'to_status' => $e->to_status, 'reason' => $manager ? $e->reason : null, 'at' => $e->created_at->toIso8601String()]);
        }
        return $result;
    }

    public function create(User $user, array $data): Asset {
        abort_unless($this->manages($user), 403);
        return DB::transaction(function () use ($data, $user) {
            $asset = Asset::create($data);
            $this->movement($asset, $user, 'registered', 'available', null, 'Asset registered in inventory.');
            return $asset;
        });
    }

    public function transition(User $user, string $uuid, array $data): Asset {
        return DB::transaction(function () use ($user, $uuid, $data) {
            $asset = $this->visible($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($asset->version === (int) $data['version'], 409, 'This asset changed. Refresh its details and try again.');
            $action = $data['action']; $from = $asset->status; $oldEmployee = $asset->employee_id;
            if ($action === 'acknowledge') {
                abort_unless($user->allows('assets.self') && in_array($oldEmployee, $this->scope->ownEmployeeIds($user)), 403);
                if ($from !== 'assigned' || $asset->acknowledged_at) $this->invalid('Only an unacknowledged assignment can be acknowledged.');
                $asset->acknowledged_at = now();
            } else {
                abort_unless($this->manages($user), 403);
                if (in_array($action, ['assign', 'transfer'])) {
                    if (($action === 'assign' && $from !== 'available') || ($action === 'transfer' && $from !== 'assigned')) $this->invalid('The asset is not available for this custody change.');
                    $employee = Employee::where('uuid', $data['employee_uuid'] ?? null)->whereIn('status', [...Employee::WORKING, 'preboarding'])->firstOrFail();
                    if ($employee->id === $oldEmployee) $this->invalid('Choose a different custodian.');
                    $asset->employee_id = $employee->id; $asset->status = 'assigned'; $asset->acknowledged_at = null;
                } elseif ($action === 'return') {
                    if (! in_array($from, ['assigned', 'lost'])) $this->invalid('Only an assigned or recovered lost asset can be returned.');
                    $asset->condition = $data['condition'] ?? 'good'; $asset->status = $asset->condition === 'damaged' ? 'repair' : 'available';
                    $asset->employee_id = null; $asset->acknowledged_at = null;
                } elseif ($action === 'repair') {
                    if ($from !== 'available') $this->invalid('Return the asset before sending it for repair.');
                    $asset->status = 'repair'; $asset->condition = 'damaged';
                } elseif ($action === 'restore') {
                    if ($from !== 'repair') $this->invalid('Only assets under repair can be restored.');
                    $asset->status = 'available'; $asset->condition = 'good';
                } elseif ($action === 'lost') {
                    if (! in_array($from, ['assigned', 'available'])) $this->invalid('Only assigned or available assets can be marked lost.');
                    $asset->status = 'lost';
                } elseif ($action === 'dispose') {
                    if (! in_array($from, ['available', 'repair']) || $asset->employee_id) $this->invalid('Only returned inventory can be disposed.');
                    $asset->status = 'disposed';
                } else $this->invalid('Unsupported custody action.');
            }
            $asset->version++; $asset->save();
            $this->tasks->completeBySource('assets', 'asset:'.$asset->uuid);
            if (in_array($action, ['assign', 'transfer'])) {
                $recipient = Actors::userIdOf($asset->employee_id);
                if ($recipient && User::whereKey($recipient)->where('active', true)->exists()) $this->tasks->createOnce([
                    'assignee_id' => $recipient, 'title' => 'Acknowledge your assigned asset', 'source_module' => 'assets', 'source_entity' => 'asset:'.$asset->uuid,
                    'action' => '/assets/'.$asset->uuid, 'priority' => 'normal', 'status' => 'open',
                ]);
            }
            $this->movement($asset, $user, $action, $from, $oldEmployee, $data['reason'] ?? 'Receipt acknowledged by custodian.');
            return $asset->refresh();
        });
    }

    private function movement(Asset $asset, User $user, string $action, string $from, ?int $oldEmployee, string $reason): void {
        AssetMovement::create(['asset_id' => $asset->id, 'actor_id' => $user->id, 'action' => $action, 'from_status' => $from, 'to_status' => $asset->status,
            'from_employee_id' => $oldEmployee, 'to_employee_id' => $asset->employee_id, 'reason' => $reason]);
        Audit::record('asset.'.$action, 'assets', 'asset:'.$asset->uuid);
    }
    private function invalid(string $message): never { throw ValidationException::withMessages(['asset' => $message]); }
}
