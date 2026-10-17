<?php

use App\Http\Controllers\Analytics\AnalyticsController as A;
use App\Http\Controllers\Analytics\AnalyticsOpsController as O;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/analytics')->middleware(['module:analytics', 'permission:analytics.view'])->group(function () {
    Route::get('metrics', [A::class, 'catalog']);
    Route::post('query', [A::class, 'query']);
    Route::get('reports', [A::class, 'reports']);
    Route::post('reports', [A::class, 'save']);
    Route::post('reports/{uuid}/run', [A::class, 'run']);
    Route::delete('reports/{uuid}', [A::class, 'delete']);
    Route::match(['GET', 'POST'], 'export', [A::class, 'export'])->middleware('permission:analytics.export');
    Route::post('scenarios', [A::class, 'scenario']);

    // Explainability, role dashboards, scheduled delivery, async exports.
    Route::get('metrics/{key}', [O::class, 'explain']);
    Route::get('dashboards', [O::class, 'dashboards']);
    Route::get('dashboards/{uuid}', [O::class, 'dashboard']);
    Route::middleware('permission:analytics.dashboards.manage')->group(function () {
        Route::post('dashboards', [O::class, 'saveDashboard']);
        Route::put('dashboards/{uuid}', [O::class, 'saveDashboard']);
        Route::delete('dashboards/{uuid}', [O::class, 'deleteDashboard']);
        Route::get('schedules', [O::class, 'schedules']);
        Route::post('schedules', [O::class, 'saveSchedule']);
        Route::put('schedules/{uuid}', [O::class, 'saveSchedule']);
        Route::delete('schedules/{uuid}', [O::class, 'deleteSchedule']);
    });
    Route::get('deliveries', [O::class, 'deliveries']);
    Route::get('deliveries/{uuid}/link', [O::class, 'deliveryLink']);
    Route::get('deliveries/{uuid}/download', [O::class, 'deliveryDownload'])->name('analytics.delivery.download')->middleware('signed:relative');
    Route::middleware('permission:analytics.export')->group(function () {
        Route::get('exports', [O::class, 'exportJobs']);
        Route::post('exports', [O::class, 'requestExport']);
        Route::get('exports/{uuid}', [O::class, 'exportJob']);
        Route::get('exports/{uuid}/download', [O::class, 'downloadExport']);
    });
});

// Business Impact Intelligence: business results read against workforce cost (cost respects payroll permission and data scope).
Route::prefix('v1/analytics/impact')->middleware(['module:analytics', 'permission:analytics.view', 'permission:analytics.impact.view'])->group(function () {
    $I = \App\Http\Controllers\Analytics\ImpactController::class;
    Route::get('metrics', [$I, 'metrics']);
    Route::get('report', [$I, 'report']);
    Route::middleware('permission:analytics.impact.manage')->group(function () use ($I) {
        Route::post('metrics', [$I, 'create']);
        Route::patch('metrics/{uuid}', [$I, 'update'])->whereUuid('uuid');
        Route::put('metrics/{uuid}/values', [$I, 'saveValues'])->whereUuid('uuid');
        Route::delete('metrics/{uuid}/values/{period}', [$I, 'deleteValue'])->whereUuid('uuid');
        Route::post('import', [$I, 'import'])->middleware('throttle:20,1');
    });
});
