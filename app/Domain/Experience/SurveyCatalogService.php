<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Experience\Models\{SurveyBankQuestion, SurveyScale};
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SurveyCatalogService
{
    public function questions(): array
    {
        return SurveyBankQuestion::where('active', true)->orderBy('category')->orderBy('id')->limit(500)->get()
            ->map(fn ($row) => ['uuid' => $row->uuid, 'prompt' => $row->prompt, 'category' => $row->category])->all();
    }

    public function createQuestion(User $actor, array $data): array
    {
        $question = SurveyBankQuestion::create(['uuid' => (string) Str::uuid(), 'prompt' => trim($data['prompt']), 'category' => $data['category'] ?? null, 'created_by' => $actor->id]);
        Audit::record('survey.bank_question_created', 'experience', 'survey_question:'.$question->uuid);
        return ['uuid' => $question->uuid, 'prompt' => $question->prompt, 'category' => $question->category];
    }

    public function scales(): array
    {
        $this->defaultScale();
        return SurveyScale::where('active', true)->orderBy('minimum_value')->orderBy('code')->limit(100)->get()->map(fn ($scale) => $this->presentScale($scale))->all();
    }

    public function createScale(User $actor, array $data): array
    {
        $code = strtoupper($data['code']);
        if (SurveyScale::where('code', $code)->exists()) throw ValidationException::withMessages(['code' => 'That scale code is already in use in this workspace.']);
        $labels = isset($data['labels']) ? [] : null;
        foreach (array_values($data['labels'] ?? []) as $offset => $label) $labels[$data['minimum_value'] + $offset] = $label;
        $scale = SurveyScale::create(['uuid' => (string) Str::uuid(), 'code' => $code, 'name' => trim($data['name']),
            'minimum_value' => $data['minimum_value'], 'maximum_value' => $data['maximum_value'], 'labels' => $labels, 'created_by' => $actor->id]);
        Audit::record('survey.scale_created', 'experience', 'survey_scale:'.$scale->uuid);
        return $this->presentScale($scale);
    }

    public function resolveScale(?string $code): SurveyScale
    {
        $scale = $code ? SurveyScale::where('code', strtoupper($code))->where('active', true)->first() : null;
        if ($code && ! $scale) throw ValidationException::withMessages(['scale_code' => 'Choose an active survey scale from this workspace.']);
        return $scale ?? $this->defaultScale();
    }

    public function resolveQuestion(string $uuid): SurveyBankQuestion
    {
        return SurveyBankQuestion::where('uuid', $uuid)->where('active', true)->firstOrFail();
    }

    public function presentScale(SurveyScale $scale): array
    {
        return ['uuid' => $scale->uuid, 'code' => $scale->code, 'name' => $scale->name, 'minimum_value' => (int) $scale->minimum_value,
            'maximum_value' => (int) $scale->maximum_value, 'labels' => $scale->labels ?? []];
    }

    private function defaultScale(): SurveyScale
    {
        return SurveyScale::firstOrCreate(['code' => 'FIVE'], ['uuid' => (string) Str::uuid(), 'name' => 'Agreement (1–5)', 'minimum_value' => 1,
            'maximum_value' => 5, 'labels' => ['1' => 'Strongly disagree', '2' => 'Disagree', '3' => 'Neither agree nor disagree', '4' => 'Agree', '5' => 'Strongly agree']]);
    }
}
