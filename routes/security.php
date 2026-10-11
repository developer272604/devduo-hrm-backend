<?php

use App\Http\Controllers\Security\AccessReviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/security')->middleware('module:security')->group(function () {
    Route::get('access-reviews', [AccessReviewController::class, 'campaigns'])->middleware('permission:security.access_reviews.view|security.access_reviews.manage');
    Route::post('access-reviews', [AccessReviewController::class, 'create'])->middleware('permission:security.access_reviews.manage');
    Route::get('access-reviews/{uuid}/items', [AccessReviewController::class, 'items'])->middleware('permission:security.access_reviews.view|security.access_reviews.manage');
    Route::post('access-reviews/{campaign}/items/{item}/review', [AccessReviewController::class, 'review'])->middleware('permission:security.access_reviews.manage');
});
