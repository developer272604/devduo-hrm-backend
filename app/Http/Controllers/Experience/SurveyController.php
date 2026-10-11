<?php

namespace App\Http\Controllers\Experience;

use App\Domain\Experience\Models\Survey;
use App\Domain\Experience\SurveyService;
use App\Domain\Experience\SurveyCatalogService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

final class SurveyController
{
    public function __construct(private SurveyService $surveys, private SurveyCatalogService $catalog) {}

    public function index(Request $r)
    {
        $rows = $this->surveys->visible($r->user())->orderByDesc('opens_at')->limit(500)->get()
            ->filter(fn ($survey) => $r->user()->allows('experience.survey.manage') || $this->surveys->eligible($r->user(), $survey))->values();
        $perPage = min(50, max(1, (int) $r->query('per_page', 20)));
        $page = max(1, (int) $r->query('page', 1));
        return new LengthAwarePaginator($rows->forPage($page, $perPage)->map(fn ($survey) => $this->surveys->present($survey, $r->user())), $rows->count(), $perPage, $page, ['path' => $r->url()]);
    }

    public function audienceOptions() { return $this->surveys->audienceOptions(); }

    public function bankQuestions() { return $this->catalog->questions(); }

    public function addBankQuestion(Request $r)
    {
        $data = $r->validate(['prompt' => 'required|string|max:300', 'category' => 'nullable|string|max:50']);
        return response()->json($this->catalog->createQuestion($r->user(), $data), 201);
    }

    public function scales() { return $this->catalog->scales(); }

    public function addScale(Request $r)
    {
        $data = $r->validate(['code' => ['required','string','max:30','regex:/^[A-Za-z0-9_-]+$/'], 'name' => 'required|string|max:100',
            'minimum_value' => 'required|integer|min:1|max:10', 'maximum_value' => 'required|integer|min:1|max:10|gte:minimum_value',
            'labels' => 'nullable|array|max:10', 'labels.*' => 'required|string|max:80']);
        if (isset($data['labels']) && count($data['labels']) !== $data['maximum_value'] - $data['minimum_value'] + 1) {
            throw \Illuminate\Validation\ValidationException::withMessages(['labels' => 'Provide one label for each value in the scale, or leave labels empty.']);
        }
        return response()->json($this->catalog->createScale($r->user(), $data), 201);
    }

    public function create(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'description' => 'nullable|string|max:2000',
            'opens_at' => 'nullable|date_format:Y-m-d', 'closes_at' => 'nullable|date_format:Y-m-d', 'retention_until' => 'required|date_format:Y-m-d',
            'campaign_type' => ['sometimes', Rule::in(['engagement', 'pulse', 'onboarding', 'exit', 'custom'])], 'requires_consent' => 'sometimes|boolean',
            'response_mode' => ['sometimes', Rule::in(['anonymous', 'confidential', 'identified'])],
            'reminder_interval_days' => 'nullable|integer|between:1,30', 'max_reminders' => 'sometimes|integer|between:0,5', 'scale_code' => 'nullable|string|max:30',
            'minimum_responses' => 'sometimes|integer|min:5|max:1000', 'eligibility' => 'sometimes|array:legal_entity_ids,location_ids,designation_ids',
            'eligibility.legal_entity_ids' => 'sometimes|array|max:50', 'eligibility.legal_entity_ids.*' => 'required|integer|min:1|distinct',
            'eligibility.location_ids' => 'sometimes|array|max:50', 'eligibility.location_ids.*' => 'required|integer|min:1|distinct',
            'eligibility.designation_ids' => 'sometimes|array|max:50', 'eligibility.designation_ids.*' => 'required|integer|min:1|distinct',
            'questions' => 'required|array|min:1|max:10', 'questions.*.prompt' => 'nullable|string|max:300', 'questions.*.bank_question_uuid' => 'nullable|uuid',
            'questions.*.scale_code' => 'nullable|string|max:30']);
        foreach ($d['questions'] as $index => $question) if (empty($question['prompt']) && empty($question['bank_question_uuid'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(["questions.$index" => 'Choose a saved question or provide a prompt.']);
        }
        if (($d['response_mode'] ?? 'anonymous') !== 'anonymous' && empty($d['requires_consent'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['requires_consent' => 'Confidential and identified surveys require explicit participant consent.']);
        }
        if (! empty($d['opens_at']) && ! empty($d['closes_at']) && $d['closes_at'] < $d['opens_at']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['closes_at' => 'The close date must be on or after the open date.']);
        }
        if ($d['retention_until'] <= ($d['closes_at'] ?? $d['opens_at'] ?? now()->toDateString())) throw \Illuminate\Validation\ValidationException::withMessages(['retention_until' => 'Retention must continue beyond the survey close date.']);
        if (($d['max_reminders'] ?? 0) > 0 && empty($d['reminder_interval_days'])) throw \Illuminate\Validation\ValidationException::withMessages(['reminder_interval_days' => 'Set the reminder interval to enable reminders.']);
        $survey = $this->surveys->create($r->user(), $d);
        return response()->json($this->surveys->present($survey, $r->user()), 201);
    }

    public function publish(Request $r, string $uuid)
    {
        $survey = Survey::where('uuid', $uuid)->firstOrFail();
        return $this->surveys->present($this->surveys->publish($survey, $r->user()), $r->user());
    }

    public function submit(Request $r, string $uuid)
    {
        $survey = $this->surveys->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        $d = $r->validate(['answers' => 'required|array|min:1|max:10', 'answers.*' => 'required|integer|min:1|max:10', 'consent' => 'sometimes|boolean']);
        $this->surveys->submit($survey, $r->user(), $d['answers'], $d['consent'] ?? false);
        return response()->json(['submitted' => true]);
    }

    public function results(Request $r, string $uuid)
    {
        return $this->surveys->results(Survey::where('uuid', $uuid)->firstOrFail());
    }

    public function identifiedResponses(Request $r, string $uuid)
    {
        abort_unless($r->user()->hasTenantScope(), 403);
        $survey = Survey::where('uuid', $uuid)->firstOrFail();
        $result = $this->surveys->identifiedResponses($survey);
        \App\Domain\Audit\Audit::record('survey.identified_responses_accessed', 'experience', 'survey:'.$survey->uuid, ['suppressed' => $result['suppressed'] ?? false]);
        return response()->json($result)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function export(Request $r, string $uuid)
    {
        $survey = Survey::where('uuid', $uuid)->firstOrFail();
        $result = $this->surveys->results($survey);
        if (! empty($result['retention_expired'])) throw \Illuminate\Validation\ValidationException::withMessages(['survey' => 'Survey data has expired and cannot be exported.']);
        if (! empty($result['suppressed'])) throw \Illuminate\Validation\ValidationException::withMessages(['survey' => 'Aggregate export is unavailable until the response threshold is reached.']);
        \App\Domain\Audit\Audit::record('survey.aggregate_exported', 'experience', 'survey:'.$survey->uuid, ['response_mode' => $survey->response_mode]);
        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Question', 'Rating', 'Label', 'Responses']);
            foreach ($result['questions'] as $question) foreach ($question['distribution'] as $row) {
                fputcsv($out, [$this->safeCsvCell($question['prompt']), $row['rating'], $this->safeCsvCell($row['label'] ?? ''), $row['suppressed'] ? 'Suppressed' : $row['count']]);
            }
            fclose($out);
        }, 'survey-aggregates-'.$survey->uuid.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function safeCsvCell(string $value): string
    {
        return preg_match('/^[\t\r\n]/', $value) || preg_match('/^\s*[=+@\-]/u', $value) ? "'".$value : $value;
    }
}
