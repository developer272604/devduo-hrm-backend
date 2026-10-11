<?php

use App\Http\Controllers\People\Insights\ActionCenterController;
use App\Http\Controllers\People\Insights\Employee360Controller;
use App\Http\Controllers\People\Insights\OrgChartController;
use App\Http\Controllers\People\Insights\QualityController;
use App\Http\Controllers\People\Insights\TimelineController;
use Illuminate\Support\Facades\Route;

// Routes for the insights workstream (inside prefix v1, module:people).

// Timeline, Organization Memory, Employee 360, completeness
Route::get('employees/{uuid}/timeline', [TimelineController::class, 'timeline'])->middleware('permission:people.view');
Route::get('people/timeline/types', [TimelineController::class, 'types'])->middleware('permission:people.view');
Route::get('employees/{uuid}/memory', [TimelineController::class, 'memory'])->middleware('permission:people.view');
Route::get('people/memory/headcount', [TimelineController::class, 'headcount'])->middleware('permission:people.view');
Route::get('employees/{uuid}/360', [Employee360Controller::class, 'show'])->middleware('permission:people.view');
Route::get('employees/{uuid}/completeness', [Employee360Controller::class, 'completeness'])->middleware('permission:people.view');
Route::get('people/completeness/summary', [Employee360Controller::class, 'completenessSummary'])->middleware('permission:people.quality.view');

// Data quality
Route::get('people/quality/findings', [QualityController::class, 'index'])->middleware('permission:people.quality.view');
Route::get('people/quality/summary', [QualityController::class, 'summary'])->middleware('permission:people.quality.view');
Route::post('people/quality/scan', [QualityController::class, 'scan'])->middleware(['permission:people.quality.resolve', 'throttle:people-quality-scan']);
Route::post('people/quality/findings/{id}/{action}', [QualityController::class, 'act'])->whereNumber('id')->whereIn('action', ['resolve', 'dismiss', 'acknowledge', 'assign', 'reopen'])
    ->middleware('permission:people.quality.resolve');

// Action center
Route::get('people/action-center', [ActionCenterController::class, 'index'])->middleware('permission:people.quality.view');
Route::get('people/action-center/{key}/employees', [ActionCenterController::class, 'employees'])->where('key', '[a-z_.]+')->middleware('permission:people.quality.view');
Route::post('people/action-center/remind', [ActionCenterController::class, 'remind'])->middleware(['permission:people.quality.resolve', 'throttle:people-remind']);
Route::post('people/action-center/tasks', [ActionCenterController::class, 'task'])->middleware('permission:people.quality.resolve');

// Org chart and dashboard
Route::get('people/org-chart', [OrgChartController::class, 'index'])->middleware('permission:people.view');
Route::get('people/org-chart/search', [OrgChartController::class, 'search'])->middleware('permission:people.view');
Route::get('people/org-chart/{uuid}/children', [OrgChartController::class, 'children'])->middleware('permission:people.view');
Route::get('people/dashboard', [OrgChartController::class, 'dashboard'])->middleware('permission:people.view');
