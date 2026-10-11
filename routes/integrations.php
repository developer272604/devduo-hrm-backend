<?php

use App\Http\Controllers\Integrations\IntegrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/integrations')->middleware('module:integrations')->group(function () {
    Route::get('providers', [IntegrationController::class, 'providers'])->middleware('permission:integrations.view|integrations.manage');
    Route::get('connections', [IntegrationController::class, 'connections'])->middleware('permission:integrations.view|integrations.manage');
    Route::post('connections', [IntegrationController::class, 'create'])->middleware('permission:integrations.manage');
    Route::get('connections/{uuid}/runs', [IntegrationController::class, 'runs'])->middleware('permission:integrations.view|integrations.manage');
    Route::post('connections/{uuid}/test', [IntegrationController::class, 'test'])->middleware('permission:integrations.manage');
    Route::post('connections/{uuid}/revoke', [IntegrationController::class, 'revoke'])->middleware('permission:integrations.manage');
});
