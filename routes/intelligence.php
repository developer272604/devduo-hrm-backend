<?php

use App\Http\Controllers\Intelligence\FindingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/intelligence')->middleware('module:intelligence')->group(function () {
    Route::get('findings', FindingsController::class)->middleware('permission:intelligence.view');
});
