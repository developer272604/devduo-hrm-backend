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

// Phase 14 platform runtime: API clients, outbound webhooks, file import profiles/runs, health center.
Route::prefix('v1/integrations')->middleware('module:integrations')->group(function () {
    $view = 'permission:integrations.view|integrations.manage|integrations.api_clients.manage|integrations.webhooks.manage|integrations.imports.manage';
    $C = \App\Http\Controllers\Integrations\PlatformController::class;
    Route::get('health', [$C, 'health'])->middleware($view);
    Route::get('diagnostics', [$C, 'diagnostics'])->middleware($view);

    Route::get('api-clients', [$C, 'clients'])->middleware($view);
    Route::post('api-clients', [$C, 'createClient'])->middleware('permission:integrations.api_clients.manage');
    Route::patch('api-clients/{uuid}', [$C, 'updateClient'])->whereUuid('uuid')->middleware('permission:integrations.api_clients.manage');
    Route::post('api-clients/{uuid}/rotate', [$C, 'rotateClient'])->whereUuid('uuid')->middleware('permission:integrations.api_clients.manage');
    Route::post('api-clients/{uuid}/revoke', [$C, 'revokeClient'])->whereUuid('uuid')->middleware('permission:integrations.api_clients.manage');
    Route::get('api-clients/{uuid}/requests', [$C, 'clientRequests'])->whereUuid('uuid')->middleware($view);

    Route::get('webhooks', [$C, 'webhooks'])->middleware($view);
    Route::post('webhooks', [$C, 'createWebhook'])->middleware('permission:integrations.webhooks.manage');
    Route::patch('webhooks/{uuid}', [$C, 'updateWebhook'])->whereUuid('uuid')->middleware('permission:integrations.webhooks.manage');
    Route::delete('webhooks/{uuid}', [$C, 'deleteWebhook'])->whereUuid('uuid')->middleware('permission:integrations.webhooks.manage');
    Route::post('webhooks/{uuid}/rotate-secret', [$C, 'rotateWebhookSecret'])->whereUuid('uuid')->middleware('permission:integrations.webhooks.manage');
    Route::post('webhooks/{uuid}/test', [$C, 'testWebhook'])->whereUuid('uuid')->middleware('permission:integrations.webhooks.manage');
    Route::get('webhooks/{uuid}/deliveries', [$C, 'deliveries'])->whereUuid('uuid')->middleware($view);
    Route::post('webhook-deliveries/{uuid}/replay', [$C, 'replay'])->whereUuid('uuid')->middleware('permission:integrations.webhooks.manage');

    Route::get('import-profiles', [$C, 'profiles'])->middleware($view);
    Route::post('import-profiles', [$C, 'createProfile'])->middleware('permission:integrations.imports.manage');
    Route::patch('import-profiles/{uuid}', [$C, 'updateProfile'])->whereUuid('uuid')->middleware('permission:integrations.imports.manage');
    Route::post('import-profiles/{uuid}/runs', [$C, 'runProfile'])->whereUuid('uuid')->middleware(['permission:integrations.imports.manage', 'throttle:20,1']);
    Route::get('import-runs', [$C, 'runs'])->middleware($view);
    Route::get('import-runs/{uuid}/items', [$C, 'runItems'])->whereUuid('uuid')->middleware($view);
    Route::post('import-runs/{uuid}/retry', [$C, 'retryRun'])->whereUuid('uuid')->middleware('permission:integrations.imports.manage');

    // Migration Studio: ordered, verified moves from another HRMS using the existing module imports.
    Route::prefix('migration-projects')->middleware('permission:integrations.migration.view')->group(function () {
        $M = \App\Http\Controllers\Migration\MigrationController::class;
        $manage = 'permission:integrations.migration.manage';
        Route::get('/', [$M, 'index']);
        Route::post('/', [$M, 'store'])->middleware($manage);
        Route::get('{uuid}', [$M, 'show'])->whereUuid('uuid');
        foreach (['link', 'unlink', 'skip', 'reconcile', 'verify', 'reopen', 'go-live', 'cancel'] as $action) {
            Route::post('{uuid}/'.$action, [$M, \Illuminate\Support\Str::camel($action)])->whereUuid('uuid')->middleware($manage);
        }
    });
});
