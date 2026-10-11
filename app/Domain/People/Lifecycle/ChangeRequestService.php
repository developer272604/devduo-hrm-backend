<?php

namespace App\Domain\People\Lifecycle;

use App\Domain\Access\Classification;
use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Config\CustomFieldService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Domain\People\Records\AddressService;
use App\Domain\People\Records\BankService;
use App\Domain\People\Records\IdentityRules;
use App\Domain\People\Records\IdentityService;
use App\Domain\People\Records\Masking;
use App\Domain\People\Sections\ContactService;
use App\Domain\People\Sections\DependentService;
use App\Domain\People\Sections\EmergencyContactService;
use App\Domain\People\Services\EmployeeRules;
use App\Domain\People\Services\EmployeeService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Http\Controllers\People\Records\AddressController;
use App\Http\Controllers\People\Records\BankAccountController;
use App\Http\Controllers\People\Records\IdentityController;
use App\Http\Controllers\People\Sections\ContactController;
use App\Http\Controllers\People\Sections\DependentController;
use App\Http\Controllers\People\Sections\EmergencyContactController;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Profile change requests: an employee proposes a change to approved information, an approver with scope decides, and an approval
 * is applied through the very same services and validation rules as the direct endpoints (no second code path).
 * Old and requested values are stored encrypted; audit entries, events, tasks and notifications never carry values.
 */
final class ChangeRequestService
{
    public const SECTIONS = ['personal', 'contact', 'address', 'emergency', 'dependent', 'bank', 'identity', 'custom'];

    public const OPERATIONS = ['create', 'update', 'delete'];

    /** Sections whose values are masked in responses unless the viewer may reveal sensitive data. */
    public const SENSITIVE = ['bank', 'identity'];

    private const GROUP = ['personal' => 'personal', 'custom' => 'personal', 'contact' => 'contact', 'address' => 'address', 'emergency' => 'emergency', 'dependent' => 'dependent', 'bank' => 'bank', 'identity' => 'identity'];

    /** Extra permission an approver needs besides people.changes.approve. */
    private const EXTRA_PERMISSION = ['bank' => 'people.bank.manage', 'identity' => 'people.identity.manage'];

    private const CONTROLLERS = ['contact' => ContactController::class, 'address' => AddressController::class, 'emergency' => EmergencyContactController::class,
        'dependent' => DependentController::class, 'bank' => BankAccountController::class, 'identity' => IdentityController::class];

    private const LABELS = ['personal' => 'personal information', 'contact' => 'contact details', 'address' => 'address', 'emergency' => 'emergency contact', 'dependent' => 'dependent',
        'bank' => 'bank account', 'identity' => 'identity record', 'custom' => 'additional information'];

    public function __construct(private DomainEvents $events, private DataScope $scope, private FieldAccess $access, private TaskService $tasks, private NotificationService $notifications) {}

    // ---- submit ---------------------------------------------------------------------------------

    /** @param array{section:string,operation:string,target_id?:?string,requested_value?:?array,effective_date?:?string,comments?:?string} $input */
    public function submit(User $user, Employee $employee, array $input): ProfileChangeRequest
    {
        abort_unless($this->scope->isSelf($user, $employee), 403, 'Change requests are submitted by the employee. HR can edit this information directly.');
        $section = $input['section'];
        $operation = $input['operation'];
        abort_if($this->access->editGroup($user, self::GROUP[$section], $employee) === 'none', 403, 'You cannot request changes to this part of your profile.');
        abort_if(in_array($section, ['personal', 'custom'], true) && $operation !== 'update', 422, 'This section can only be updated.');
        abort_if(in_array($employee->status, ['archived'], true), 422, 'This employment is archived.');

        $targetUuid = $input['target_id'] ?? null;
        $existing = null;
        if ($operation === 'create') {
            abort_if($targetUuid, 422, 'A new record has no target.');
        } elseif (! in_array($section, ['personal', 'custom'], true)) {
            if (! $targetUuid || ! Str::isUuid($targetUuid)) {
                throw ValidationException::withMessages(['target_id' => ['Choose the record this change applies to.']]);
            }
            $existing = $this->find($section, $employee, $targetUuid);
        }
        $value = $operation === 'delete' ? [] : $this->validateValue($section, $operation, (array) ($input['requested_value'] ?? []), $employee, $existing, $user);
        if ($operation !== 'delete') {
            abort_if($value === [], 422, 'Enter the information you want to change.');
        }

        return DB::transaction(function () use ($user, $employee, $section, $operation, $existing, $targetUuid, $value, $input) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $duplicate = ProfileChangeRequest::query()->where('employee_id', $employee->id)->where('status', 'pending')->where('section', $section)
                ->where('operation', $operation)->where('target_uuid', $targetUuid)->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['section' => ['There is already a pending request for this information. Cancel it first or wait for the decision.']]);
            }
            $row = ProfileChangeRequest::query()->create([
                'employee_id' => $employee->id, 'person_id' => $employee->person_id, 'section' => $section, 'operation' => $operation, 'target_id' => $existing?->id, 'target_uuid' => $targetUuid,
                'old_value' => $existing || $section === 'personal' ? $this->seal($this->currentValues($section, $employee, $existing, array_keys($value))) : null,
                'requested_value' => $this->seal($value), 'requested_by' => $user->id, 'status' => 'pending',
                'effective_date' => $input['effective_date'] ?? null, 'comments' => $input['comments'] ?? null,
            ]);
            Audit::record('change_requested', 'people', 'employee:'.$employee->uuid, ['request' => $row->uuid, 'section' => $section, 'operation' => $operation]);
            $this->events->record('ProfileChangeRequested', ['employee' => $employee, 'subject' => ['profile_change_request', $row->id], 'classification' => Classification::EMPLOYEE,
                'summary' => 'Requested a change to '.self::LABELS[$section].'.', 'payload' => ['request' => $row->uuid, 'section' => $section, 'operation' => $operation]]);
            $this->notifyApprovers($row, $employee);

            return $row;
        });
    }

    // ---- decide ---------------------------------------------------------------------------------

    public function approve(User $user, ProfileChangeRequest $request, ?string $comment): ProfileChangeRequest
    {
        return $this->decide($user, $request, 'approved', $comment);
    }

    public function reject(User $user, ProfileChangeRequest $request, string $comment): ProfileChangeRequest
    {
        return $this->decide($user, $request, 'rejected', $comment);
    }

    private function decide(User $user, ProfileChangeRequest $request, string $to, ?string $comment): ProfileChangeRequest
    {
        $employee = Employee::query()->with('person')->findOrFail($request->employee_id);
        abort_unless($this->canDecide($user, $request, $employee), 403, 'You cannot decide this request.');

        return DB::transaction(function () use ($user, $request, $to, $comment, $employee) {
            $row = ProfileChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'pending') {
                abort_if($row->status !== $to, 409, 'This request was already '.$row->status.'.');

                return $row; // same decision repeated: idempotent
            }
            if ($to === 'approved') {
                $this->apply($user, $row, $employee);
            }
            $row->fill(['status' => $to, 'approver_id' => $user->id, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_comment' => $comment])->save();

            $label = self::LABELS[$row->section];
            Audit::record($to === 'approved' ? 'change_approved' : 'change_rejected', 'people', 'employee:'.$employee->uuid, ['request' => $row->uuid, 'section' => $row->section, 'operation' => $row->operation]);
            $this->events->record($to === 'approved' ? 'ProfileChangeApproved' : 'ProfileChangeRejected', ['employee' => $employee, 'subject' => ['profile_change_request', $row->id],
                'classification' => Classification::EMPLOYEE, 'summary' => 'Change to '.$label.' '.$to.'.', 'payload' => ['request' => $row->uuid, 'section' => $row->section, 'operation' => $row->operation]]);
            $this->tasks->completeBySource('people', 'change_request:'.$row->uuid);
            $requester = User::query()->where('tenant_id', $row->tenant_id)->whereKey($row->requested_by)->where('active', true)->first();
            if ($requester) {
                $this->notifications->send($requester->id, 'Profile change '.$to, 'Your request to change your '.$label.' was '.$to.'.'.($comment ? ' Comment: '.$comment : ''), 'people', '/employees/'.$employee->uuid);
            }

            return $row;
        });
    }

    public function cancel(User $user, ProfileChangeRequest $request): ProfileChangeRequest
    {
        abort_unless($request->requested_by === $user->id, 403, 'Only the person who made the request can cancel it.');

        return DB::transaction(function () use ($request) {
            $row = ProfileChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($row->status === 'cancelled') {
                return $row;
            }
            abort_if($row->status !== 'pending', 409, 'This request was already '.$row->status.'.');
            $row->fill(['status' => 'cancelled', 'decided_at' => now()])->save();
            Audit::record('change_cancelled', 'people', 'person:'.$row->person_id, ['request' => $row->uuid]);
            $this->tasks->completeBySource('people', 'change_request:'.$row->uuid);

            return $row;
        });
    }

    // ---- authorization --------------------------------------------------------------------------

    public function canDecide(User $user, ProfileChangeRequest $request, Employee $employee): bool
    {
        if ($request->requested_by === $user->id || $this->scope->isSelf($user, $employee) || ! $user->allows('people.changes.approve')) {
            return false;
        }
        $extra = self::EXTRA_PERMISSION[$request->section] ?? null;
        if ($extra && ! $user->allows($extra)) {
            return false;
        }

        return $this->scope->canAccess($user, $employee, 'people.changes.approve') && ($extra === null || $this->scope->canAccess($user, $employee, $extra));
    }

    /** Whether the viewer may read the request: its requester, the employee, or an approver in scope. */
    public function canView(User $user, ProfileChangeRequest $request, Employee $employee): bool
    {
        if ($request->requested_by === $user->id || $this->scope->isSelf($user, $employee)) {
            return true;
        }
        $extra = self::EXTRA_PERMISSION[$request->section] ?? null;

        return $user->allows('people.changes.approve') && ($extra === null || $user->allows($extra)) && $this->scope->canAccess($user, $employee, 'people.changes.approve');
    }

    /** Sections an approver must not see because they lack the section's manage permission. */
    public function hiddenSections(User $user): array
    {
        return array_keys(array_filter(self::EXTRA_PERMISSION, fn ($permission) => ! $user->allows($permission)));
    }

    // ---- reads ----------------------------------------------------------------------------------

    public function present(ProfileChangeRequest $row, Employee $employee, User $viewer, bool $detail): array
    {
        $reveal = $viewer->allows('people.sensitive.reveal') && $this->scope->canAccess($viewer, $employee, 'people.sensitive.reveal');
        $names = User::query()->where('tenant_id', $row->tenant_id)->whereIn('id', array_filter([$row->requested_by, $row->decided_by]))->pluck('name', 'id');
        $shape = fn (?string $sealed) => $sealed === null ? null : $this->maskFor($row->section, $this->open($sealed), $reveal && $detail);

        return [
            'uuid' => $row->uuid, 'employee' => ['uuid' => $employee->uuid, 'name' => $employee->person?->display_name, 'employee_number' => $employee->employee_number],
            'section' => $row->section, 'operation' => $row->operation, 'target_id' => $row->target_uuid, 'status' => $row->status,
            'fields' => array_keys($this->open($row->requested_value)),
            'old_value' => $shape($row->old_value) ?? new \stdClass, 'requested_value' => $shape($row->requested_value) ?? new \stdClass,
            'masked' => in_array($row->section, self::SENSITIVE, true) && ! ($reveal && $detail),
            'requested_by' => $names[$row->requested_by] ?? null, 'requested_at' => $row->created_at?->toIso8601String(), 'effective_date' => $row->effective_date?->toDateString(),
            'comments' => $row->comments, 'decided_by' => $row->decided_by ? ($names[$row->decided_by] ?? null) : null, 'decided_at' => $row->decided_at?->toIso8601String(),
            'decision_comment' => $row->decision_comment,
            'can_decide' => $row->status === 'pending' && $this->canDecide($viewer, $row, $employee), 'can_cancel' => $row->status === 'pending' && $row->requested_by === $viewer->id,
        ];
    }

    // ---- internals ------------------------------------------------------------------------------

    private function apply(User $actor, ProfileChangeRequest $row, Employee $employee): void
    {
        $value = $this->open($row->requested_value);
        $uuid = $row->target_uuid;
        $op = $row->operation;
        switch ($row->section) {
            case 'personal':
                app(EmployeeService::class)->updatePerson($employee->person, $this->validateValue('personal', 'update', $value, $employee, null), $employee);
                break;
            case 'custom':
                app(CustomFieldService::class)->setValues($actor, $employee, (array) ($value['values'] ?? []), $value['effective_from'] ?? null);
                break;
            case 'contact':
            case 'emergency':
            case 'dependent':
                $service = app(['contact' => ContactService::class, 'emergency' => EmergencyContactService::class, 'dependent' => DependentService::class][$row->section]);
                $data = $op === 'delete' ? [] : $this->validateValue($row->section, $op, $value, $employee, $op === 'update' ? $service->find($employee, $uuid) : null);
                match ($op) { 'create' => $service->create($employee, $data), 'update' => $service->update($employee, $uuid, $data), 'delete' => $service->delete($employee, $uuid) };
                break;
            case 'address':
                $service = app(AddressService::class);
                $record = $op === 'create' ? null : $service->find($employee, $uuid);
                $data = $op === 'delete' ? [] : $this->validateValue('address', $op, $value, $employee, $record);
                match ($op) { 'create' => $service->create($employee, $data), 'update' => $service->update($employee, $record, $data), 'delete' => $service->delete($employee, $record) };
                break;
            case 'bank':
                $service = app(BankService::class);
                $record = $op === 'create' ? null : $service->find($employee, $uuid);
                $data = $op === 'delete' ? [] : $this->validateValue('bank', $op, $value, $employee, $record);
                match ($op) { 'create' => $service->create($actor, $employee, $data), 'update' => $service->update($actor, $employee, $record, $data), 'delete' => $service->retire($actor, $employee, $record, null) };
                break;
            case 'identity':
                $service = app(IdentityService::class);
                $record = $op === 'create' ? null : $service->find($employee, $uuid);
                $data = $op === 'delete' ? [] : $this->validateValue('identity', $op, $value, $employee, $record);
                match ($op) { 'create' => $service->create($actor, $employee, $data, false), 'update' => $service->update($actor, $employee, $record, $data, false), 'delete' => $service->delete($actor, $employee, $record) };
                break;
        }
    }

    /** Validates with the rules of the direct endpoints. Errors are reported under requested_value.*. */
    private function validateValue(string $section, string $operation, array $value, Employee $employee, ?Model $existing, ?User $requester = null): array
    {
        $create = $operation === 'create';
        $messages = [];
        if ($section === 'personal') {
            $rules = collect(EmployeeRules::person(false))->except(['country', 'timezone'])->all();
            foreach (array_keys($value) as $field) {
                if (! isset($rules[$field])) {
                    throw ValidationException::withMessages(['requested_value.'.$field => ['This field cannot be changed through a request.']]);
                }
                if ($requester && $this->access->edit($requester, 'person.'.$field, $employee) === 'none') {
                    throw ValidationException::withMessages(['requested_value.'.$field => ['You cannot request a change to this field.']]);
                }
            }
        } elseif ($section === 'custom') {
            Validator::make($value, ['values' => ['required', 'array', 'min:1'], 'effective_from' => ['nullable', 'date_format:Y-m-d']])->validate();

            return Arr::only($value, ['values', 'effective_from']);
        } else {
            [$rules, $messages] = $this->controllerRules(self::CONTROLLERS[$section], $create, $existing, $value);
        }
        try {
            $data = Validator::make($value, $rules, $messages)->validate();
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $key => $list) {
                $errors['requested_value.'.$key] = $list;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->deepCheck($section, $create, $data, $employee, $existing);

        return $data;
    }

    /** Country-aware checks that live in the services (bank schema, identity formats), run early so bad requests never reach an approver. */
    private function deepCheck(string $section, bool $create, array $data, Employee $employee, ?Model $existing): void
    {
        try {
            if ($section === 'bank' && isset($data['details'])) {
                app(BankService::class)->details(strtoupper($data['country'] ?? $existing?->country ?? $employee->country), (array) $data['details']);
            }
            if ($section === 'identity' && ($create || isset($data['number']))) {
                $rules = app(IdentityRules::class);
                $country = $rules->country($data['country'] ?? $existing?->country);
                $rules->number($rules->type($country, (string) ($data['type'] ?? $existing?->type), 'identity'), (string) ($data['number'] ?? ''));
            }
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $key => $list) {
                $errors['requested_value.'.$key] = $list;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array{0:array,1:array} rules and messages of the direct endpoint's validation, reused as-is */
    private function controllerRules(string $class, bool $create, ?Model $existing, array $value): array
    {
        $controller = app($class);
        if (isset($value['kind']) && $class === ContactController::class) {
            request()->merge(['kind' => $value['kind']]); // ContactController reads the kind from the request on create
        }
        $method = new \ReflectionMethod($controller, 'rules');
        $rules = $method->getNumberOfParameters() >= 2 ? $method->invoke($controller, $create, $existing) : $method->invoke($controller, $create);
        $messages = method_exists($controller, 'messages') ? (new \ReflectionMethod($controller, 'messages'))->invoke($controller) : [];

        return [$rules, $messages];
    }

    private function find(string $section, Employee $employee, string $uuid): Model
    {
        try {
            return match ($section) {
                'contact' => app(ContactService::class)->find($employee, $uuid),
                'emergency' => app(EmergencyContactService::class)->find($employee, $uuid),
                'dependent' => app(DependentService::class)->find($employee, $uuid),
                'address' => app(AddressService::class)->find($employee, $uuid),
                'bank' => app(BankService::class)->find($employee, $uuid),
                'identity' => app(IdentityService::class)->find($employee, $uuid),
            };
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            throw ValidationException::withMessages(['target_id' => ['That record was not found on your profile.']]);
        }
    }

    /** Current values of the fields a request touches (sensitive sections are stored in their masked presentation). */
    private function currentValues(string $section, Employee $employee, ?Model $existing, array $fields): array
    {
        if ($section === 'personal') {
            return $this->plain($employee->person->only($fields));
        }
        if ($section === 'bank') {
            return Arr::only(app(BankService::class)->present($existing), array_merge($fields, ['account_masked']));
        }
        if ($section === 'identity') {
            return Arr::only(app(IdentityService::class)->present($existing), array_merge($fields, ['number_masked']));
        }

        return $existing ? $this->plain($existing->only($fields)) : [];
    }

    private function plain(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $values);
    }

    private function maskFor(string $section, array $value, bool $reveal): array
    {
        if (! in_array($section, self::SENSITIVE, true) || $reveal) {
            return $value;
        }
        $mask = function ($v) use (&$mask) {
            if (is_array($v)) {
                return array_map($mask, $v);
            }

            return is_string($v) && mb_strlen($v) > 0 && ! str_starts_with($v, Masking::BULLETS) ? Masking::value($v) : $v;
        };
        $out = [];
        foreach ($value as $key => $v) {
            // Descriptive fields stay readable; account and identifier values are shortened to their last characters.
            $out[$key] = in_array($key, ['account_holder', 'bank_name', 'country', 'currency', 'type', 'is_primary', 'effective_from', 'effective_to', 'issue_date', 'expiry_date'], true) ? $v : $mask($v);
        }

        return $out;
    }

    private function seal(array $value): string
    {
        return Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function open(?string $sealed): array
    {
        if ($sealed === null) {
            return [];
        }
        try {
            return (array) json_decode(Crypt::decryptString($sealed), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
    }

    private function notifyApprovers(ProfileChangeRequest $row, Employee $employee): void
    {
        $name = $employee->person?->display_name ?? 'An employee';
        $found = 0;
        foreach (User::query()->where('tenant_id', app(TenantContext::class)->id)->where('active', true)->where('id', '!=', $row->requested_by)->orderBy('id')->cursor() as $candidate) {
            if (! $this->canDecide($candidate, $row, $employee)) {
                continue;
            }
            $this->tasks->createOnce(['assignee_id' => $candidate->id, 'title' => 'Review profile change: '.$name, 'description' => ucfirst(self::LABELS[$row->section]).' change requested.', 'source_module' => 'people',
                'source_entity' => 'change_request:'.$row->uuid, 'priority' => 'normal', 'action' => '/people/change-requests/'.$row->uuid, 'metadata' => ['request' => $row->uuid, 'employee_uuid' => $employee->uuid]]);
            $this->notifications->send($candidate->id, 'Profile change to review', $name.' requested a change to '.self::LABELS[$row->section].'.', 'people', '/people/change-requests/'.$row->uuid);
            if (++$found >= 25) {
                break;
            }
        }
    }
}
