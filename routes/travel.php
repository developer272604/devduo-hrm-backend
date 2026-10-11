<?php
use App\Http\Controllers\Travel\TravelController;
use App\Http\Controllers\Travel\TravelDeskController as Desk;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/travel')->middleware('module:travel')->group(function () {
    Route::get('requests', [TravelController::class, 'index'])->middleware('permission:travel.self|travel.approve');
    Route::post('requests', [TravelController::class, 'create'])->middleware('permission:travel.self');
    Route::get('requests/{uuid}', [TravelController::class, 'show'])->middleware('permission:travel.self|travel.approve');
    Route::put('requests/{uuid}/itinerary', [TravelController::class, 'itinerary'])->middleware('permission:travel.self');
    Route::post('requests/{uuid}/actions', [TravelController::class, 'action'])->middleware('permission:travel.self|travel.approve');
    Route::get('requests/{uuid}/desk', [Desk::class, 'show'])->middleware('permission:travel.self|travel.approve|travel.manage');
    Route::post('requests/{uuid}/advances', [Desk::class, 'requestAdvance'])->middleware('permission:travel.self');
    Route::post('requests/{uuid}/bookings', [Desk::class, 'addBooking'])->middleware('permission:travel.self|travel.manage');
    Route::get('requests/{uuid}/reconciliation', [Desk::class, 'reconciliation'])->middleware('permission:travel.self|travel.approve|travel.manage');
    Route::post('requests/{uuid}/reconciliation', [Desk::class, 'close'])->middleware('permission:travel.manage');
    Route::post('advances/{uuid}/decision', [Desk::class, 'decideAdvance'])->middleware('permission:travel.approve');
    Route::post('advances/{uuid}/cancel', [Desk::class, 'cancelAdvance'])->middleware('permission:travel.self');
    Route::post('advances/{uuid}/reference', [Desk::class, 'reference'])->middleware('permission:travel.manage');
    Route::post('bookings/{uuid}/cancel', [Desk::class, 'cancelBooking'])->middleware('permission:travel.self|travel.manage');
});
