<?php

namespace App\Domain\People\Records;

use App\Domain\Access\DataScope;
use App\Domain\Events\DomainEvent;
use App\Domain\Events\DomainEvents;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Finds documents, identity records and work authorizations expiring inside the window, records ONE DocumentExpiring event
 * per item and window (idempotent) and opens a My Work task for HR users who can verify documents. Runs inside a tenant context.
 */
final class ExpiryScanner
{
    private const SOURCES = [
        'document' => ['table' => 'employee_documents', 'soft' => true, 'tab' => 'documents', 'label' => 'Document'],
        'identity' => ['table' => 'identity_documents', 'soft' => false, 'tab' => 'identity', 'label' => 'Identity record'],
        'work_authorization' => ['table' => 'work_authorizations', 'soft' => false, 'tab' => 'identity', 'label' => 'Work authorization'],
    ];

    /** @return array{events:int,tasks:int} */
    public function scan(int $tenantId, ?int $days = null): array
    {
        $days ??= Expiry::windowDays();
        $limit = \Carbon\CarbonImmutable::parse(TenantClock::today())->addDays($days)->toDateString();
        $today = TenantClock::today();
        $result = ['events' => 0, 'tasks' => 0];
        $reviewers = null;

        foreach (self::SOURCES as $kind => $source) {
            $rows = DB::table($source['table'])->where('tenant_id', $tenantId)->whereNotNull('expiry_date')->where('expiry_date', '<=', $limit)
                ->when($source['soft'], fn ($q) => $q->whereNull('deleted_at'))
                ->when($kind === 'work_authorization', fn ($q) => $q->whereIn('status', ['active', 'pending']))->get();
            foreach ($rows as $row) {
                $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('person_id', $row->person_id)->whereNull('deleted_at')->orderByDesc('employment_sequence')->first();
                if (! $employee) {
                    continue;
                }
                $already = DomainEvent::query()->where('type', 'DocumentExpiring')->where('subject_type', $kind)->where('subject_id', $row->id)
                    ->whereDate('effective_date', $row->expiry_date)->where('payload->window_days', $days)->exists();
                if ($already) {
                    continue;
                }
                $expired = $row->expiry_date < $today;
                $model = \App\Domain\People\Models\Employee::query()->find($employee->id);
                app(DomainEvents::class)->record('DocumentExpiring', [
                    'employee' => $model, 'subject' => [$kind, $row->id], 'actor_id' => null, 'classification' => 'hr_confidential', 'effective_date' => $row->expiry_date,
                    'summary' => $source['label'].($expired ? ' has expired.' : ' is expiring soon.'),
                    'payload' => ['kind' => $kind, 'record' => $row->uuid, 'expiry_date' => $row->expiry_date, 'window_days' => $days, 'state' => $expired ? 'expired' : 'expiring'],
                ]);
                $result['events']++;
                $reviewers ??= $this->reviewers($tenantId);
                foreach ($reviewers as $user) {
                    if (! app(DataScope::class)->canAccess($user, $model, 'people.documents.verify')) {
                        continue;
                    }
                    $task = app(TaskService::class)->createOnce([
                        'assignee_id' => $user->id, 'title' => $source['label'].($expired ? ' expired' : ' expiring').' for '.\App\Domain\People\Models\Person::query()->whereKey($employee->person_id)->value('display_name'),
                        'description' => 'Expiry date: '.$row->expiry_date.'. Review and request a renewal.', 'source_module' => 'people', 'source_entity' => $kind.':'.$row->uuid,
                        'priority' => $expired ? 'high' : 'normal', 'due_date' => $row->expiry_date < $today ? $today : $row->expiry_date,
                        'action' => '/people/'.$employee->uuid.'?tab='.$source['tab'],
                    ]);
                    $task && $result['tasks']++;
                }
            }
        }

        return $result;
    }

    /** @return \Illuminate\Support\Collection<int,User> */
    private function reviewers(int $tenantId)
    {
        return User::query()->where('tenant_id', $tenantId)->where('active', true)->get()->filter(fn (User $u) => $u->allows('people.documents.verify'))->values();
    }
}
