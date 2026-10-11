<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Experience\Models\{Survey, SurveyDelivery, SurveyParticipant};
use App\Domain\People\Models\{Assignment, Employee};
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Notifications\NotificationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SurveyDeliveryService
{
    public function __construct(private NotificationService $notifications) {}

    public function deliverDue(): int
    {
        $today = TenantClock::today();
        $surveys = Survey::where('status', 'published')->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', $today))
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', $today))
            ->where(fn ($q) => $q->whereNull('retention_until')->orWhere('retention_until', '>=', $today))->whereNull('data_purged_at')
            ->orderBy('id')->limit(200)->get();
        $processed = 0;
        foreach ($surveys as $survey) {
            $activity = false;
            $submittedUserIds = SurveyParticipant::where('survey_id', $survey->id)->where('submitted', true)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            foreach ($this->eligibleUserIds($survey, $today) as $userId) {
                DB::transaction(function () use ($survey, $userId, $submittedUserIds, &$activity) {
                    $delivery = SurveyDelivery::firstOrCreate(['survey_id' => $survey->id, 'user_id' => $userId]);
                    if (! $delivery->invitation_processed_at) {
                        $notification = $this->notifications->send($userId, 'Employee survey available', 'A survey is available for your feedback. Participation is optional; review the consent information before responding.', 'experience.survey', '/experience/surveys');
                        $delivery->update(['invitation_processed_at' => now(), 'invited_at' => $notification ? now() : null,
                            'invitation_suppressed_at' => $notification ? null : now()]);
                        $activity = $activity || (bool) $notification;
                        return;
                    }
                    if (! $delivery->invited_at || ! $survey->reminder_interval_days || ! $survey->max_reminders || $delivery->reminder_count >= $survey->max_reminders) return;
                    if (in_array($userId, $submittedUserIds, true)) return;
                    $last = $delivery->last_reminded_at ?? $delivery->invited_at;
                    if ($last->gt(now()->subDays($survey->reminder_interval_days))) return;
                    $notification = $this->notifications->send($userId, 'Survey reminder', 'A survey is still open for your feedback. Participation is optional; review the consent information before responding.', 'experience.survey', '/experience/surveys');
                    if ($notification) {
                        $delivery->update(['last_reminded_at' => now(), 'reminder_count' => $delivery->reminder_count + 1]);
                        $activity = true;
                    }
                });
            }
            if ($activity) Audit::record('survey.deliveries_processed', 'experience', 'survey:'.$survey->uuid);
            $processed++;
        }
        return $processed;
    }

    /** The campaign invite goes only to active, linked employees who meet every current assignment filter. */
    private function eligibleUserIds(Survey $survey, string $date): array
    {
        $assignments = Assignment::where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
        $filters = $survey->eligibility_filters ?? [];
        foreach (['legal_entity_ids' => 'legal_entity_id', 'location_ids' => 'location_id', 'designation_ids' => 'designation_id'] as $key => $column) {
            if (! empty($filters[$key])) $assignments->whereIn($column, $filters[$key]);
        }
        $employeeIds = $assignments->pluck('employee_id')->unique()->all();
        if (! $employeeIds) return [];
        $candidateIds = Employee::with('person')->whereIn('id', $employeeIds)->whereIn('status', Employee::WORKING)
            ->whereHas('person', fn ($q) => $q->whereNotNull('user_id'))->get()->map(fn ($employee) => $employee->person?->user_id)
            ->filter()->unique()->all();
        return User::where('tenant_id', app(TenantContext::class)->id)->where('active', true)->whereIn('id', $candidateIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
