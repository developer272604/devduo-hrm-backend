<?php

namespace App\Http\Controllers\People\Config;

use App\Domain\People\Config\PeopleSettingsService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

class SettingsConfigController extends PeopleController
{
    public function show(PeopleSettingsService $settings)
    {
        return $settings->show();
    }

    public function update(Request $r, string $section, PeopleSettingsService $settings)
    {
        return $settings->update($section, $r->all());
    }

    public function previewNumbering(Request $r, PeopleSettingsService $settings)
    {
        return $settings->previewNumbering($r->query());
    }
}
