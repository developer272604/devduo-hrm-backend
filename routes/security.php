<?php

use App\Http\Controllers\Security\AccessReviewController;
use App\Http\Controllers\Security\MfaController;
use App\Http\Controllers\Security\SecurityAdminController;
use App\Http\Controllers\Security\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/security')->middleware('module:security')->group(function () {
    Route::get('access-reviews', [AccessReviewController::class, 'campaigns'])->middleware('permission:security.access_reviews.view|security.access_reviews.manage');
    Route::post('access-reviews', [AccessReviewController::class, 'create'])->middleware('permission:security.access_reviews.manage');
    Route::get('access-reviews/{uuid}/items', [AccessReviewController::class, 'items'])->middleware('permission:security.access_reviews.view|security.access_reviews.manage');
    Route::post('access-reviews/{campaign}/items/{item}/review', [AccessReviewController::class, 'review'])->middleware('permission:security.access_reviews.manage');

    // Own account: any signed-in user (these stay reachable while MFA enrolment is being enforced).
    Route::prefix('mfa')->middleware('throttle:20,1')->group(function () {
        Route::get('/', [MfaController::class, 'status']);
        Route::post('enroll', [MfaController::class, 'enroll']);
        Route::post('confirm', [MfaController::class, 'confirm']);
        Route::post('recovery-codes', [MfaController::class, 'regenerate']);
        Route::post('disable', [MfaController::class, 'disable']);
    });
    Route::get('sessions', [SessionController::class, 'index']);
    Route::post('sessions/revoke-others', [SessionController::class, 'revokeOthers']);
    Route::delete('sessions/{uuid}', [SessionController::class, 'revoke']);

    // Administration.
    Route::delete('users/{user}/mfa', [MfaController::class, 'adminReset'])->whereNumber('user')->middleware('permission:security.mfa.manage');
    Route::post('users/{user}/sessions/revoke', [SessionController::class, 'revokeUser'])->whereNumber('user')->middleware('permission:security.sessions.manage');
    Route::get('settings', [SecurityAdminController::class, 'settings'])->middleware('permission:security.settings.manage');
    Route::put('settings', [SecurityAdminController::class, 'updateSettings'])->middleware('permission:security.settings.manage');
    Route::get('audit', [SecurityAdminController::class, 'auditSearch'])->middleware('permission:security.audit.view');
    Route::post('audit/verify', [SecurityAdminController::class, 'auditVerify'])->middleware('permission:security.audit.verify');
    Route::get('audit/export', [SecurityAdminController::class, 'auditExport'])->middleware(['permission:security.audit.export', 'throttle:6,1']);
    Route::get('legal-holds', [SecurityAdminController::class, 'holds'])->middleware('permission:security.legal_hold.manage');
    Route::post('legal-holds', [SecurityAdminController::class, 'placeHold'])->middleware('permission:security.legal_hold.manage');
    Route::post('legal-holds/{uuid}/release', [SecurityAdminController::class, 'releaseHold'])->middleware('permission:security.legal_hold.manage');
    Route::get('retention-policies', [SecurityAdminController::class, 'retention'])->middleware('permission:security.retention.manage|security.legal_hold.manage');
    Route::post('retention-policies', [SecurityAdminController::class, 'saveRetention'])->middleware('permission:security.retention.manage');
    Route::put('retention-policies/{uuid}', [SecurityAdminController::class, 'saveRetention'])->middleware('permission:security.retention.manage');
});

// Continuous Compliance Evidence Vault: controls, evidence with independent review, auditor pack.
Route::prefix('v1/security/compliance')->middleware(['module:security', 'permission:security.compliance.view'])->group(function () {
    $C = \App\Http\Controllers\Security\ComplianceController::class;
    Route::get('controls', [$C, 'index']);
    Route::get('controls/{uuid}', [$C, 'show'])->whereUuid('uuid');
    Route::post('controls/{uuid}/evidence', [$C, 'submit'])->whereUuid('uuid'); // the owner may submit; the service refuses anyone else without manage
    Route::middleware('permission:security.compliance.manage')->group(function () use ($C) {
        Route::post('controls', [$C, 'store']);
        Route::patch('controls/{uuid}', [$C, 'update'])->whereUuid('uuid');
        Route::post('remind', [$C, 'remind']);
    });
    Route::middleware('permission:security.compliance.review')->group(function () use ($C) {
        Route::get('review-queue', [$C, 'queue']);
        Route::post('evidence/{uuid}/review', [$C, 'review'])->whereUuid('uuid');
        Route::get('pack', [$C, 'pack']);
    });
});
