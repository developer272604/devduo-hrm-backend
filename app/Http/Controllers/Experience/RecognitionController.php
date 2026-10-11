<?php

namespace App\Http\Controllers\Experience;

use App\Domain\Experience\Models\Recognition;
use App\Domain\Experience\RecognitionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RecognitionController
{
    public function __construct(private RecognitionService $recognition) {}

    public function index(Request $r)
    {
        $q = $r->user()->allows('experience.recognition.manage') ? $this->recognition->pending() : $this->recognition->feed($r->user());
        return $q->paginate(min(50, max(1, (int) $r->query('per_page', 20))))->through(fn ($item) => $this->recognition->present($item));
    }

    public function create(Request $r)
    {
        $d = $r->validate(['recipient_uuid' => 'required|uuid', 'message' => 'required|string|max:2000',
            'value_tags' => 'required|array|min:1|max:5', 'value_tags.*' => ['required', 'distinct', Rule::in(RecognitionService::VALUES)],
            'reward_reference' => 'nullable|string|max:100']);
        return response()->json($this->recognition->present($this->recognition->create($r->user(), $d)), 201);
    }

    public function recipients(Request $r)
    {
        $d = $r->validate(['search' => 'nullable|string|min:2|max:80']);
        return empty($d['search']) ? [] : $this->recognition->recipients($r->user(), trim($d['search']));
    }

    public function moderate(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        $item = Recognition::where('uuid', $uuid)->firstOrFail();
        return $this->recognition->present($this->recognition->moderate($item, $r->user(), $d['decision']));
    }

    public function preference(Request $r)
    {
        $data = $r->validate(['opted_out' => 'required|boolean']);
        return ['opted_out' => $this->recognition->preference($r->user(), $data['opted_out'])->opted_out];
    }

    public function myPreference(Request $r)
    {
        return ['opted_out' => $this->recognition->currentPreference($r->user())];
    }
}
