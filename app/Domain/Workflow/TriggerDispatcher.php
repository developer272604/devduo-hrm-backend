<?php

namespace App\Domain\Workflow;

use App\Domain\Events\DomainEvent;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Workflow\Models\Definition;
use App\Domain\Workflow\Models\Version;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Durable bounded delivery queue. Cursor updates and enqueues commit together. */
final class TriggerDispatcher
{
    private function table(string $name)
    {
        return DB::table($name)->where('tenant_id', app(TenantContext::class)->id);
    }

    public function dispatch(): int
    {
        foreach (Definition::whereNotNull('published_version_id')->cursor() as $definition) {
            DB::transaction(function () use ($definition) {
                $v = Version::findOrFail($definition->published_version_id);
                $trigger = $v->spec['trigger'] ?? ['type' => 'manual'];
                if ($trigger['type'] === 'manual') {
                    return;
                }
                $this->table('workflow_trigger_cursors')->insertOrIgnore(['tenant_id' => $v->tenant_id, 'version_id' => $v->id, 'event_id' => $v->spec['event_after_id'] ?? 0,
                    'next_at' => $trigger['type'] === 'schedule' ? CarbonImmutable::parse($trigger['at'])->setTimezone(config('app.timezone')) : null, 'created_at' => now(), 'updated_at' => now()]);
                $cursor = $this->table('workflow_trigger_cursors')->where('version_id', $v->id)->lockForUpdate()->first();
                if ($trigger['type'] === 'event') {
                    // Read bounded batches of all events to advance over unrelated event types, too.
                    $events = DomainEvent::where('id', '>', $cursor->event_id)->orderBy('id')->limit(200)->get();
                    foreach ($events as $e) {
                        $statusMatches = ! isset($trigger['status']) || data_get($e->payload, 'to') === $trigger['status'];
                        if ($e->type === $trigger['event_type'] && $e->employee_id && $statusMatches) {
                            $this->enqueue($v, $e->employee_id, 'event:'.$e->uuid);
                        }
                    }
                    if ($events->isNotEmpty()) {
                        $this->table('workflow_trigger_cursors')->where('id', $cursor->id)->update(['event_id' => $events->last()->id, 'updated_at' => now()]);
                    }
                } elseif ($cursor->next_at && CarbonImmutable::parse($cursor->next_at)->isPast()) {
                    $employee = Employee::where('uuid', $trigger['employee_uuid'])->first();
                    if ($employee) {
                        $this->enqueue($v, $employee->id, 'schedule:'.CarbonImmutable::parse($cursor->next_at)->utc()->format('YmdHis'));
                    }
                    $interval = (int) ($trigger['interval_hours'] ?? 0);
                    // Coalesce missed schedule slots; never flood a tenant after downtime.
                    $this->table('workflow_trigger_cursors')->where('id', $cursor->id)->update(['next_at' => $interval ? now()->addHours($interval) : null, 'updated_at' => now()]);
                }
            });
        }
        $count = 0;
        foreach ($this->table('workflow_deliveries')->whereIn('status', ['pending', 'retry'])->where('next_at', '<=', now())->orderBy('id')->limit(100)->pluck('id') as $id) {
            DB::transaction(function () use ($id) {
                $delivery = $this->table('workflow_deliveries')->where('id', $id)->lockForUpdate()->first();
                if (! $delivery || ! in_array($delivery->status, ['pending', 'retry']) || CarbonImmutable::parse($delivery->next_at)->isFuture()) {
                    return;
                }
                $v = Version::findOrFail($delivery->version_id);
                $d = Definition::findOrFail($v->definition_id);
                if ((int) $d->published_version_id !== $v->id) {
                    $this->table('workflow_deliveries')->where('id', $id)->update(['status' => 'superseded', 'updated_at' => now()]);

                    return;
                }
                try {
                    $actor = User::where('tenant_id', $v->tenant_id)->findOrFail($v->published_by);
                    $employee = Employee::findOrFail($delivery->employee_id);
                    $i = app(WorkflowService::class)->start($actor, $d->uuid, $employee->uuid, 'delivery:'.$id, $v->id);
                    $this->table('workflow_deliveries')->where('id', $id)->update(['status' => 'delivered', 'instance_uuid' => $i->uuid, 'updated_at' => now()]);
                } catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|ModelNotFoundException $e) {
                    $attempt = $delivery->attempts + 1;
                    $this->table('workflow_deliveries')->where('id', $id)->update(['attempts' => $attempt, 'status' => $attempt >= 5 ? 'dead_letter' : 'retry',
                        'failure_code' => 'delivery_authorization_or_source_unavailable', 'next_at' => now()->addMinutes(2 ** $attempt), 'updated_at' => now()]);
                }
            });
            $count++;
        }

        return $count;
    }

    private function enqueue(Version $v, int $employee, string $source): void
    {
        $this->table('workflow_deliveries')->insertOrIgnore(['tenant_id' => $v->tenant_id, 'version_id' => $v->id, 'employee_id' => $employee, 'source_key' => $source,
            'status' => 'pending', 'attempts' => 0, 'next_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
