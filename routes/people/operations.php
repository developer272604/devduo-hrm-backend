<?php

use App\Http\Controllers\People\Operations\BulkController;
use App\Http\Controllers\People\Operations\ExportController;
use App\Http\Controllers\People\Operations\ImportController;
use App\Http\Controllers\People\Operations\ViewController;
use Illuminate\Support\Facades\Route;

// Routes for the operations workstream (inside prefix v1, module:people).
Route::prefix('people')->group(function () {
    Route::middleware('permission:people.import')->group(function () {
        Route::get('imports/template', [ImportController::class, 'template']);
        Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:20,1');
        Route::get('imports', [ImportController::class, 'index']);
        Route::get('imports/{uuid}', [ImportController::class, 'show'])->whereUuid('uuid');
        Route::delete('imports/{uuid}', [ImportController::class, 'destroy'])->whereUuid('uuid');
        Route::put('imports/{uuid}/mapping', [ImportController::class, 'mapping'])->whereUuid('uuid');
        Route::post('imports/{uuid}/validate', [ImportController::class, 'validateImport'])->whereUuid('uuid');
        Route::post('imports/{uuid}/commit', [ImportController::class, 'commit'])->whereUuid('uuid');
        Route::get('imports/{uuid}/rows', [ImportController::class, 'rows'])->whereUuid('uuid');
        Route::patch('imports/{uuid}/rows/{number}', [ImportController::class, 'patchRow'])->whereUuid('uuid')->whereNumber('number');
        Route::get('imports/{uuid}/errors.csv', [ImportController::class, 'errorsCsv'])->whereUuid('uuid');
        Route::post('imports/{uuid}/retry', [ImportController::class, 'retry'])->whereUuid('uuid');
        Route::post('imports/{uuid}/rollback', [ImportController::class, 'rollback'])->whereUuid('uuid');
    });

    Route::middleware('permission:people.export')->group(function () {
        Route::get('exports/columns', [ExportController::class, 'columns']);
        Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:20,1');
        Route::get('exports', [ExportController::class, 'index']);
        Route::get('exports/{uuid}', [ExportController::class, 'show'])->whereUuid('uuid');
        Route::get('exports/{uuid}/download', [ExportController::class, 'download'])->whereUuid('uuid');
    });

    Route::middleware('permission:people.bulk')->group(function () {
        Route::get('bulk/actions', [BulkController::class, 'actions']);
        Route::post('bulk/preview', [BulkController::class, 'preview'])->middleware('throttle:30,1');
        Route::post('bulk/apply', [BulkController::class, 'apply'])->middleware('throttle:30,1');
        Route::get('bulk/runs', [BulkController::class, 'runs']);
        Route::get('bulk/runs/{uuid}', [BulkController::class, 'run'])->whereUuid('uuid');
    });

    Route::middleware('permission:people.view')->group(function () {
        Route::get('directory/columns', [ViewController::class, 'columns']);
        Route::get('views', [ViewController::class, 'index']);
        Route::post('views', [ViewController::class, 'store']);
        Route::get('views/{uuid}', [ViewController::class, 'show'])->whereUuid('uuid');
        Route::put('views/{uuid}', [ViewController::class, 'update'])->whereUuid('uuid');
        Route::delete('views/{uuid}', [ViewController::class, 'destroy'])->whereUuid('uuid');
    });
});
