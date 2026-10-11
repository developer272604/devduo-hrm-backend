<?php

use App\Http\Controllers\Analytics\AnalyticsController as A;
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
});
