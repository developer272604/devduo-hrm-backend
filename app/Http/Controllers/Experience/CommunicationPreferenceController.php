<?php

namespace App\Http\Controllers\Experience;

use App\Domain\Experience\CommunicationPreferenceService;
use Illuminate\Http\Request;

final class CommunicationPreferenceController
{
    public function __construct(private CommunicationPreferenceService $preferences) {}

    public function show(Request $r) { return $this->preferences->present($r->user()); }

    public function update(Request $r)
    {
        $data = $r->validate(['channels' => 'required|array:announcements,surveys,recognition,events',
            ...collect(CommunicationPreferenceService::CATEGORIES)->flatMap(fn ($category) => [
                "channels.$category" => 'sometimes|array:in_app,email',
                "channels.$category.in_app" => 'sometimes|boolean', "channels.$category.email" => 'sometimes|boolean',
            ])->all()]);
        return $this->preferences->update($r->user(), $data['channels']);
    }
}
