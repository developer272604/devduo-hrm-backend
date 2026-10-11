<?php

use App\Http\Controllers\Payroll\Compensation\LegacyCompensationController as Legacy;
use App\Http\Controllers\Payroll\Config\ConfigController as Config;
use Illuminate\Support\Facades\Route;

/*
 * Workstream A (inside /api/v1/payroll): payroll groups (+ employee resolution), calendars, periods (+ transitions), salary components /
 * versions / formula checks, country packs (+ versions, publish, verify, enable), settings, exchange rates. See docs/api/payroll-config.md.
 * Pre-existing paths (groups, components, employees, compensations) keep their shapes.
 */
$read = 'permission:payroll.configure|payroll.compensation.manage|payroll.view';
$conf = 'permission:payroll.configure';
$pack = 'permission:payroll.country_pack.manage|payroll.configure';
$publish = 'permission:payroll.country_pack.publish';

// groups
Route::get('groups', [Config::class, 'groups'])->middleware($read);
Route::post('groups', [Config::class, 'createGroup'])->middleware($conf);
Route::get('group-conflicts', [Config::class, 'groupConflicts'])->middleware($conf);
Route::get('groups/{uuid}', [Config::class, 'showGroup'])->middleware($read);
Route::patch('groups/{uuid}', [Config::class, 'updateGroup'])->middleware($conf);
Route::get('groups/{uuid}/employees', [Config::class, 'groupEmployees'])->middleware($conf);
Route::post('groups/{uuid}/employees', [Config::class, 'assignEmployee'])->middleware($conf);
Route::get('employees/{uuid}/group', [Config::class, 'employeeGroup'])->middleware($conf);

// calendars and periods
Route::get('groups/{uuid}/calendars', [Config::class, 'calendars'])->middleware($read);
Route::post('groups/{uuid}/calendars', [Config::class, 'createCalendar'])->middleware($conf);
Route::post('calendars/{uuid}/generate', [Config::class, 'generatePeriods'])->middleware($conf);
Route::get('periods', [Config::class, 'periods'])->middleware($read);
Route::post('groups/{uuid}/periods', [Config::class, 'createPeriod'])->middleware($conf);
Route::get('periods/{uuid}', [Config::class, 'showPeriod'])->middleware($read);
Route::post('periods/{uuid}/transition', [Config::class, 'transitionPeriod'])->middleware($conf);

// components
Route::get('components', [Config::class, 'components'])->middleware($read);
Route::post('components', [Config::class, 'createComponent'])->middleware($conf);
Route::post('formulas/check', [Config::class, 'checkFormula'])->middleware($conf);
Route::get('components/{uuid}', [Config::class, 'showComponent'])->middleware($read);
Route::patch('components/{uuid}', [Config::class, 'updateComponent'])->middleware($conf);
Route::post('components/{uuid}/versions', [Config::class, 'componentVersion'])->middleware($conf);
Route::post('components/{uuid}/publish', [Config::class, 'publishComponent'])->middleware($conf);

// country packs
Route::get('country-packs', [Config::class, 'packs'])->middleware($read);
Route::post('country-packs', [Config::class, 'createPack'])->middleware($pack);
Route::post('country-packs/samples/install', [Config::class, 'installSample'])->middleware($pack);
Route::get('country-packs/{uuid}', [Config::class, 'showPack'])->middleware($read);
Route::post('country-packs/{uuid}/versions', [Config::class, 'createVersion'])->middleware($pack);
Route::post('country-packs/{uuid}/enable', [Config::class, 'enablePack'])->middleware($publish);
Route::post('country-packs/{uuid}/disable', [Config::class, 'disablePack'])->middleware($publish);
Route::get('country-pack-versions/{uuid}', [Config::class, 'showVersion'])->middleware($read);
Route::patch('country-pack-versions/{uuid}', [Config::class, 'updateVersion'])->middleware($pack);
Route::post('country-pack-versions/{uuid}/check', [Config::class, 'checkVersion'])->middleware($pack);
Route::post('country-pack-versions/{uuid}/simulate', [Config::class, 'simulatePack'])->middleware($pack);
Route::post('country-pack-versions/{uuid}/publish', [Config::class, 'publishVersion'])->middleware($publish);
Route::post('country-pack-versions/{uuid}/verify', [Config::class, 'verifyVersion'])->middleware($publish);
Route::post('country-pack-versions/{uuid}/retire', [Config::class, 'retireVersion'])->middleware($publish);

// settings, exchange rates
Route::get('settings', [Config::class, 'settings'])->middleware($read);
Route::put('settings', [Config::class, 'saveSettings'])->middleware($conf);
Route::get('exchange-rates', [Config::class, 'exchangeRates'])->middleware($read);
Route::post('exchange-rates', [Config::class, 'saveExchangeRate'])->middleware($conf);

// Legacy compensation endpoints (same shapes); /api/v1/compensation/* is the full API.
Route::get('employees', [Legacy::class, 'employees'])->middleware('permission:payroll.compensation.manage');
Route::get('compensations', [Legacy::class, 'compensations'])->middleware('permission:payroll.compensation.view');
Route::post('compensations', [Legacy::class, 'createCompensation'])->middleware('permission:payroll.compensation.manage');
Route::post('compensations/{uuid}/decision', [Legacy::class, 'decide'])->middleware('permission:payroll.compensation.approve');
