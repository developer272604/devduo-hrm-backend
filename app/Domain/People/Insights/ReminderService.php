<?php

namespace App\Domain\People\Insights;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * HR-initiated "request information / send reminder" notifications to employees, and "assign task" for an Action Center insight.
 * Reminders: per-employee scope check (out-of-scope and unknown uuids are indistinguishable "not_found"), de-duplicated per
 * employee and topic within 24 hours, delivered through NotificationService to the employee's linked login. The text is generic -
 * it never states which sensitive item is missing.
 */
final class ReminderService
{
    public const TOPICS = [
        'missing_documents' => ['Documents needed', 'Your HR team asks you to upload the mandatory documents that are missing from your profile.'],
        'incomplete_profile' => ['Please complete your profile', 'Your HR team asks you to complete the missing information on your profile.'],
        'expiring_documents' => ['A document or permit is expiring', 'Your HR team asks you to review and renew a document that is expiring soon.'],
        'information_request' => ['Information requested', 'Your HR team has requested information from you. Please review your profile.'],
    ];

    public const MAX_EMPLOYEES = 100;

    public function __construct(private DataScope $scope, private NotificationService $notifications, private TaskService $tasks, private PeopleInsightProvider $provider) {}

    /**
     * @param  array<int,string>  $uuids
     * @return array{sent:int,skipped:array<int,array{employee_uuid:string,reason:string}>}
     */
    public function remind(User $sender, array $uuids, string $topic): array
    {
        abort_unless(isset(self::TOPICS[$topic]), 422, 'Unknown reminder topic.');
        $uuids = array_values(array_unique($uuids));
        abort_if(count($uuids) > self::MAX_EMPLOYEES, 422, 'Send reminders to at most '.self::MAX_EMPLOYEES.' employees at a time.');
        $q = DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.tenant_id', $sender->tenant_id)->whereNull('e.deleted_at')->whereIn('e.uuid', $uuids)->whereIn('e.status', Employee::WORKING);
        $this->scope->constrain($q, $sender, 'people.quality.resolve', 'e.id');
        $rows = $q->get(['e.id', 'e.uuid', 'p.user_id'])->keyBy('uuid');
        $recent = DB::table('people_reminders')->where('tenant_id', $sender->tenant_id)->where('topic', $topic)->whereIn('employee_id', $rows->pluck('id'))->where('created_at', '>=', now()->subDay())->pluck('employee_id')->flip();
        $users = DB::table('users')->where('tenant_id', $sender->tenant_id)->where('active', true)->whereIn('id', $rows->pluck('user_id')->filter())->pluck('id')->flip();

        $sent = 0;
        $skipped = [];
        foreach ($uuids as $uuid) {
            $row = $rows[$uuid] ?? null;
            if (! $row) {
                $skipped[] = ['employee_uuid' => $uuid, 'reason' => 'not_found'];
            } elseif (isset($recent[$row->id])) {
                $skipped[] = ['employee_uuid' => $uuid, 'reason' => 'recently_reminded'];
            } elseif (! $row->user_id || ! isset($users[$row->user_id])) {
                $skipped[] = ['employee_uuid' => $uuid, 'reason' => 'no_linked_user'];
            } else {
                [$title, $body] = self::TOPICS[$topic];
                $this->notifications->send((int) $row->user_id, $title, $body, 'people', '/employees/'.$uuid);
                DB::table('people_reminders')->insert(['tenant_id' => $sender->tenant_id, 'employee_id' => $row->id, 'topic' => $topic, 'sent_by' => $sender->id, 'notified_user_id' => $row->user_id, 'created_at' => now()]);
                Audit::record('reminder_sent', 'people', 'employee:'.$uuid, ['topic' => $topic]);
                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /** Creates (once per assignee and insight) a My Work task to work through an insight's list. */
    public function assignTask(User $by, string $insightKey, int $assigneeId): array
    {
        $affected = $this->provider->affected($by, $insightKey);
        abort_if(! $affected, 422, 'There is nothing to act on for this insight.');
        abort_unless(User::query()->where('tenant_id', $by->tenant_id)->where('active', true)->whereKey($assigneeId)->exists(), 422, 'Choose an active user in this workspace.');
        [, $title, $description] = PeopleInsightProvider::CATALOGUE[$insightKey];
        $task = $this->tasks->createOnce(['assignee_id' => $assigneeId, 'title' => str_replace(':n', (string) count($affected), $title), 'description' => $description, 'source_module' => 'people',
            'source_entity' => 'insight:'.$insightKey, 'priority' => 'normal', 'due_date' => now()->addDays(7)->toDateString(), 'action' => '/employees?insight='.$insightKey, 'metadata' => ['insight' => $insightKey, 'count' => count($affected)]]);
        Audit::record('insight_task_assigned', 'people', 'insight:'.$insightKey, ['assignee_id' => $assigneeId]);

        return ['created' => (bool) $task, 'count' => count($affected)];
    }
}
