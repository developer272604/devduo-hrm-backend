<?php
use App\Http\Controllers\Travel\TravelController;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/travel')->middleware('module:travel')->group(function () {
    Route::get('requests', [TravelController::class, 'index'])->middleware('permission:travel.self|travel.approve');
    Route::post('requests', [TravelController::class, 'create'])->middleware('permission:travel.self');
    Route::get('requests/{uuid}', [TravelController::class, 'show'])->middleware('permission:travel.self|travel.approve');
    Route::put('requests/{uuid}/itinerary', [TravelController::class, 'itinerary'])->middleware('permission:travel.self');
    Route::post('requests/{uuid}/actions', [TravelController::class, 'action'])->middleware('permission:travel.self|travel.approve');
});
