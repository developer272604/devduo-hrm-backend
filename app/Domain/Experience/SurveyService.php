<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Access\DataScope;
use App\Domain\Experience\Models\{Survey, SurveyAnswerCount, SurveyConfidentialResponse, SurveyIdentifiedResponse, SurveyParticipant, SurveyQuestion};
use App\Domain\People\Models\{Assignment, Employee};
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SurveyService
{
    public function __construct(private SurveyCatalogService $catalog) {}

    public function visible(User $viewer)
    {
        $q = Survey::query()->with('questions');
        if ($viewer->allows('experience.survey.manage')) return $q;
        $today = TenantClock::today();
        return $q->where('status', 'published')->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', $today))
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', $today))
            ->where(fn ($q) => $q->whereNull('retention_until')->orWhere('retention_until', '>=', $today))->whereNull('data_purged_at');
    }

    public function present(Survey $survey, User $viewer): array
    {
        return ['uuid' => $survey->uuid, 'title' => $survey->title, 'description' => $survey->description, 'campaign_type' => $survey->campaign_type, 'response_mode' => $survey->response_mode ?? 'anonymous', 'status' => $survey->status,
            'opens_at' => $survey->opens_at?->format('Y-m-d'), 'closes_at' => $survey->closes_at?->format('Y-m-d'),
            'requires_consent' => $survey->requires_consent, 'retention_until' => $survey->retention_until?->format('Y-m-d'),
            'reminder_interval_days' => $survey->reminder_interval_days, 'max_reminders' => $survey->max_reminders,
            'submitted' => SurveyParticipant::where('survey_id', $survey->id)->where('user_id', $viewer->id)->where('submitted', true)->exists(),
            'questions' => $survey->questions->map(fn ($q) => ['uuid' => $q->uuid, 'prompt' => $q->prompt, 'kind' => $q->kind,
                'scale' => range((int) $q->scale_minimum, (int) $q->scale_maximum), 'scale_labels' => $q->scale_labels ?? []])->values()];
    }

    public function eligible(User $viewer, Survey $survey): bool
    {
        $ownIds = app(DataScope::class)->ownEmployeeIds($viewer);
        if (! $ownIds) abort(403, 'A linked employee profile is required to submit survey responses.');
        $filters = $survey->eligibility_filters ?? [];
        if (! $filters) return true;
        $today = TenantClock::today();
        $assignments = Assignment::whereIn('employee_id', $ownIds)->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->get();
        foreach ($assignments as $assignment) {
            if ((! empty($filters['legal_entity_ids']) && ! in_array($assignment->legal_entity_id, $filters['legal_entity_ids']))
                || (! empty($filters['location_ids']) && ! in_array($assignment->location_id, $filters['location_ids']))
                || (! empty($filters['designation_ids']) && ! in_array($assignment->designation_id, $filters['designation_ids']))) continue;
            return true;
        }
        return false;
    }

    public function audienceOptions(): array
    {
        $tenantId = app(\App\Domain\Tenancy\TenantContext::class)->id;
        $options = fn ($table) => DB::table($table)->where('tenant_id', $tenantId)->where('active', true)->orderBy('name')->limit(200)->get(['id', 'name'])->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->all();
        return ['legal_entities' => $options('legal_entities'), 'locations' => $options('locations'), 'designations' => $options('designations')];
    }

    public function create(User $actor, array $data): Survey
    {
        return DB::transaction(function () use ($actor, $data) {
            $survey = Survey::create(['uuid' => (string) Str::uuid(), 'title' => $data['title'], 'description' => $data['description'] ?? null,
                'status' => 'draft', 'opens_at' => $data['opens_at'] ?? TenantClock::today(), 'closes_at' => $data['closes_at'] ?? null,
                'minimum_responses' => $data['minimum_responses'] ?? 5, 'campaign_type' => $data['campaign_type'] ?? 'custom', 'requires_consent' => $data['requires_consent'] ?? false,
                'eligibility_filters' => $this->validateEligibility($data['eligibility'] ?? []), 'retention_until' => $data['retention_until'],
                'response_mode' => $data['response_mode'] ?? 'anonymous',
                'reminder_interval_days' => $data['reminder_interval_days'] ?? null, 'max_reminders' => $data['max_reminders'] ?? 0, 'created_by' => $actor->id]);
            foreach ($data['questions'] as $i => $question) {
                $bankQuestion = ! empty($question['bank_question_uuid']) ? $this->catalog->resolveQuestion($question['bank_question_uuid']) : null;
                $scale = $this->catalog->resolveScale($question['scale_code'] ?? $data['scale_code'] ?? null);
                SurveyQuestion::create(['uuid' => (string) Str::uuid(), 'survey_id' => $survey->id, 'position' => $i + 1,
                    'prompt' => $bankQuestion?->prompt ?? trim($question['prompt']), 'bank_question_id' => $bankQuestion?->id, 'kind' => 'rating',
                    'scale_minimum' => $scale->minimum_value, 'scale_maximum' => $scale->maximum_value, 'scale_labels' => $scale->labels]);
            }
            Audit::record('survey.created', 'experience', 'survey:'.$survey->uuid, ['question_count' => count($data['questions']), 'minimum_responses' => $survey->minimum_responses]);
            return $survey->load('questions');
        });
    }

    public function publish(Survey $survey, User $actor): Survey
    {
        if ($survey->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only draft surveys can be published.']);
        $survey->update(['status' => 'published', 'published_by' => $actor->id]);
        Audit::record('survey.published', 'experience', 'survey:'.$survey->uuid);
        return $survey->load('questions');
    }

    public function submit(Survey $survey, User $actor, array $answers, bool $consent = false): void
    {
        if ($survey->data_purged_at || ($survey->retention_until && $survey->retention_until->lt(TenantClock::today()))) {
            throw ValidationException::withMessages(['survey' => 'The survey data retention period has ended.']);
        }
        if ($survey->status !== 'published' || ($survey->opens_at && $survey->opens_at->gt(TenantClock::today())) || ($survey->closes_at && $survey->closes_at->lt(TenantClock::today()))) {
            throw ValidationException::withMessages(['survey' => 'This survey is not currently open.']);
        }
        if (! $this->eligible($actor, $survey)) abort(404);
        if ($survey->requires_consent && ! $consent) throw ValidationException::withMessages(['consent' => 'Consent is required to submit this survey.']);
        $questions = $survey->questions;
        $expected = $questions->pluck('uuid')->sort()->values()->all();
        $provided = collect(array_keys($answers))->sort()->values()->all();
        if ($expected !== $provided) throw ValidationException::withMessages(['answers' => 'Submit one rating for every survey question.']);
        foreach ($questions as $question) {
            $answer = (int) $answers[$question->uuid];
            if ($answer < $question->scale_minimum || $answer > $question->scale_maximum) {
                throw ValidationException::withMessages(['answers' => "Each rating must be between {$question->scale_minimum} and {$question->scale_maximum} for its question."]);
            }
        }
        DB::transaction(function () use ($survey, $actor, $answers, $questions) {
            $participant = SurveyParticipant::firstOrCreate(['survey_id' => $survey->id, 'user_id' => $actor->id]);
            $participant = SurveyParticipant::whereKey($participant->id)->lockForUpdate()->firstOrFail();
            if ($participant->submitted) throw ValidationException::withMessages(['survey' => 'You have already submitted a response.']);
            if (($survey->response_mode ?? 'anonymous') === 'anonymous') {
                foreach ($questions as $question) {
                    $bucket = SurveyAnswerCount::firstOrCreate(['survey_id' => $survey->id, 'question_id' => $question->id, 'answer' => (int) $answers[$question->uuid]], ['response_count' => 0]);
                    $bucket = SurveyAnswerCount::whereKey($bucket->id)->lockForUpdate()->firstOrFail();
                    $bucket->increment('response_count');
                }
            } else {
                $ciphertext = Crypt::encryptString(json_encode($answers, JSON_THROW_ON_ERROR));
                if ($survey->response_mode === 'confidential') {
                    SurveyConfidentialResponse::create(['survey_id' => $survey->id, 'response_uuid' => (string) Str::uuid(), 'encrypted_answers' => $ciphertext]);
                } else {
                    SurveyIdentifiedResponse::create(['survey_id' => $survey->id, 'participant_id' => $participant->id, 'encrypted_answers' => $ciphertext]);
                }
            }
            $participant->update(['submitted' => true, 'consented' => $survey->requires_consent]);
        });
    }

    public function results(Survey $survey): array
    {
        if ($survey->data_purged_at || ($survey->retention_until && $survey->retention_until->lt(TenantClock::today()))) {
            return ['retention_expired' => true];
        }
        $count = SurveyParticipant::where('survey_id', $survey->id)->where('submitted', true)->count();
        if ($count < $survey->minimum_responses) return ['suppressed' => true, 'minimum_responses' => $survey->minimum_responses];
        $questionCounts = [];
        if (($survey->response_mode ?? 'anonymous') === 'anonymous') {
            foreach ($survey->questions as $question) $questionCounts[$question->uuid] = SurveyAnswerCount::where('survey_id', $survey->id)->where('question_id', $question->id)->pluck('response_count', 'answer')->all();
        } else {
            $responses = $survey->response_mode === 'confidential'
                ? SurveyConfidentialResponse::where('survey_id', $survey->id)->pluck('encrypted_answers')
                : SurveyIdentifiedResponse::where('survey_id', $survey->id)->pluck('encrypted_answers');
            foreach ($responses as $ciphertext) {
                $answers = json_decode(Crypt::decryptString($ciphertext), true, 512, JSON_THROW_ON_ERROR);
                foreach ($answers as $questionUuid => $value) $questionCounts[$questionUuid][$value] = ($questionCounts[$questionUuid][$value] ?? 0) + 1;
            }
        }
        $questions = $survey->questions->map(function ($question) use ($survey, $count, $questionCounts) {
            $counts = $questionCounts[$question->uuid] ?? [];
            return ['uuid' => $question->uuid, 'prompt' => $question->prompt, 'scale_labels' => $question->scale_labels ?? [],
                'distribution' => collect(range((int) $question->scale_minimum, (int) $question->scale_maximum))->map(fn ($rating) => ['rating' => $rating, 'label' => ($question->scale_labels ?? [])[(string) $rating] ?? null, 'count' => (int) ($counts[$rating] ?? 0) >= $survey->minimum_responses ? (int) $counts[$rating] : null,
                    'suppressed' => (int) ($counts[$rating] ?? 0) < $survey->minimum_responses])->all()];
        })->values()->all();
        return ['suppressed' => false, 'response_count' => $count, 'minimum_responses' => $survey->minimum_responses, 'response_mode' => $survey->response_mode ?? 'anonymous', 'questions' => $questions];
    }

    public function identifiedResponses(Survey $survey): array
    {
        abort_unless($survey->response_mode === 'identified', 404);
        if ($survey->data_purged_at || ($survey->retention_until && $survey->retention_until->lt(TenantClock::today()))) {
            return ['retention_expired' => true];
        }
        $count = SurveyParticipant::where('survey_id', $survey->id)->where('submitted', true)->count();
        if ($count < $survey->minimum_responses) return ['suppressed' => true, 'minimum_responses' => $survey->minimum_responses];
        $records = SurveyIdentifiedResponse::where('survey_id', $survey->id)->orderBy('id')->limit(1000)->get();
        $participants = SurveyParticipant::whereIn('id', $records->pluck('participant_id'))->get()->keyBy('id');
        $users = User::where('tenant_id', $survey->tenant_id)->whereIn('id', $participants->pluck('user_id'))->get()->keyBy('id');
        $questions = $survey->questions->keyBy('uuid');
        return ['suppressed' => false, 'minimum_responses' => $survey->minimum_responses, 'responses' => $records->map(function ($record) use ($participants, $users, $questions) {
            $participant = $participants->get($record->participant_id);
            $user = $participant ? $users->get($participant->user_id) : null;
            $answers = json_decode(Crypt::decryptString($record->encrypted_answers), true, 512, JSON_THROW_ON_ERROR);
            return ['employee' => $user?->name, 'answers' => collect($answers)->map(function ($value, $uuid) use ($questions) {
                $question = $questions->get($uuid);
                return ['prompt' => $question?->prompt, 'value' => (int) $value, 'label' => $question?->scale_labels[(string) $value] ?? null];
            })->values()->all()];
        })->values()->all()];
    }

    public function purgeExpiredData(): int
    {
        $today = TenantClock::today();
        $purged = 0;
        foreach (Survey::whereNotNull('retention_until')->where('retention_until', '<', $today)->whereNull('data_purged_at')->get() as $survey) {
            if (\App\Domain\Security\LegalHoldService::held('experience', 'survey:'.$survey->uuid)) continue; // legal hold
            DB::transaction(function () use ($survey, &$purged) {
                SurveyAnswerCount::where('survey_id', $survey->id)->delete();
                SurveyConfidentialResponse::where('survey_id', $survey->id)->delete();
                SurveyIdentifiedResponse::where('survey_id', $survey->id)->delete();
                SurveyParticipant::where('survey_id', $survey->id)->delete();
                SurveyQuestion::where('survey_id', $survey->id)->delete();
                $survey->update(['data_purged_at' => now()]);
                Audit::record('survey.retention_purged', 'experience', 'survey:'.$survey->uuid);
                $purged++;
            });
        }
        return $purged;
    }

    private function validateEligibility(array $filters): array
    {
        $clean = array_filter($filters, fn ($ids) => ! empty($ids));
        $tables = ['legal_entity_ids' => 'legal_entities', 'location_ids' => 'locations', 'designation_ids' => 'designations'];
        foreach ($tables as $key => $table) {
            $ids = $clean[$key] ?? [];
            if (! $ids) continue;
            $valid = DB::table($table)->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->whereIn('id', $ids)->count();
            if ($valid !== count(array_unique($ids))) throw ValidationException::withMessages(['eligibility' => 'One or more eligibility organization values are invalid.']);
        }
        return $clean;
    }
}
