<?php

use App\Http\Controllers\Performance\ReviewController as Reviews;
use Illuminate\Support\Facades\Route;

/* Reviews and calibration. Required from routes/performance.php inside the v1/performance prefix + module middleware group. */
Route::get('reviews', [Reviews::class, 'index'])->middleware('permission:performance.self|performance.view');
Route::get('reviews/{uuid}', [Reviews::class, 'show'])->middleware('permission:performance.self|performance.view');
Route::post('reviews/{uuid}/peers', [Reviews::class, 'nominatePeers'])->middleware('permission:performance.self|performance.reviews.manage');
Route::post('reviews/{uuid}/manager', [Reviews::class, 'reassignManager'])->middleware('permission:performance.cycles.manage');
Route::post('reviews/{uuid}/acknowledge', [Reviews::class, 'acknowledge'])->middleware('permission:performance.self');
Route::post('review-responses/{uuid}/submit', [Reviews::class, 'submit'])->middleware('permission:performance.self');

Route::post('cycles/{uuid}/calibration/start', [Reviews::class, 'startCalibration'])->middleware('permission:performance.calibrate');
Route::get('cycles/{uuid}/calibration', [Reviews::class, 'workspace'])->middleware('permission:performance.calibrate|performance.calibrate.approve');
Route::post('reviews/{uuid}/calibration-changes', [Reviews::class, 'propose'])->middleware('permission:performance.calibrate');
Route::post('calibration-changes/{uuid}/decision', [Reviews::class, 'decide'])->middleware('permission:performance.calibrate.approve');
Route::post('cycles/{uuid}/finalize', [Reviews::class, 'finalize'])->middleware('permission:performance.calibrate.approve');
