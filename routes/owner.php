<?php

use App\Domain\Tenancy\AccountLifecycle;
use App\Http\Controllers\Owner\OwnerController;
use App\Http\Controllers\Owner\PlanController;
use Illuminate\Support\Facades\Route;

Route::prefix('owner')->middleware('owner')->group(function () {
    Route::get('overview', [OwnerController::class, 'overview']);
    Route::get('companies', [OwnerController::class, 'index']);
    Route::get('companies/{id}', [OwnerController::class, 'show'])->whereNumber('id');
    Route::post('companies/{id}/extend-trial', [OwnerController::class, 'extendTrial'])->whereNumber('id');
    Route::put('companies/{id}/subscription', [OwnerController::class, 'subscription'])->whereNumber('id');
    Route::post('companies/{id}/{action}', [OwnerController::class, 'transition'])->whereNumber('id')->whereIn('action', array_keys(AccountLifecycle::ACTIONS));
    Route::get('plans', [PlanController::class, 'index']);
    Route::post('plans', [PlanController::class, 'store']);
    Route::put('plans/{id}', [PlanController::class, 'update'])->whereNumber('id');
    Route::delete('plans/{id}', [PlanController::class, 'destroy'])->whereNumber('id');
    Route::put('users/{id}/active', [OwnerController::class, 'setUserActive'])->whereNumber('id');
    Route::put('users/{id}/role', [OwnerController::class, 'setUserRole'])->whereNumber('id');
});
