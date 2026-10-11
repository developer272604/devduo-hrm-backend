<?php

use App\Http\Controllers\Recruitment\RecruitmentController as Recruitment;
use App\Http\Controllers\Recruitment\RecruitmentCoreController as Core;
use Illuminate\Support\Facades\Route;

/* Phase 6 core. Required from routes/recruitment.php inside the v1/recruitment prefix + module middleware group. */
Route::post('imports/candidates', [\App\Http\Controllers\Recruitment\CandidateImportController::class, 'preview'])->middleware('permission:recruitment.manage');
Route::post('imports/candidates/{uuid}/commit', [\App\Http\Controllers\Recruitment\CandidateImportController::class, 'commit'])->middleware('permission:recruitment.manage');
Route::post('requisitions/{uuid}/close', [Core::class, 'closeRequisition'])->middleware('permission:recruitment.manage');
Route::get('postings', [Core::class, 'postings'])->middleware('permission:recruitment.view');
Route::post('postings', [Core::class, 'createPosting'])->middleware('permission:recruitment.manage');
Route::post('postings/{uuid}/publish', [Core::class, 'publishPosting'])->middleware('permission:recruitment.manage');
Route::post('postings/{uuid}/close', [Core::class, 'closePosting'])->middleware('permission:recruitment.manage');
Route::post('candidates/{uuid}/anonymize', [Core::class, 'anonymize'])->middleware('permission:recruitment.privacy.manage');
Route::get('interviews', [Core::class, 'interviews'])->middleware('permission:recruitment.view');
Route::post('interviews', [Core::class, 'scheduleInterview'])->middleware('permission:recruitment.manage');
Route::post('interviews/{uuid}/cancel', [Core::class, 'cancelInterview'])->middleware('permission:recruitment.manage');
Route::post('interviews/{uuid}/reschedule', [Core::class, 'rescheduleInterview'])->middleware('permission:recruitment.manage');
Route::post('interviews/{uuid}/scorecard', [Core::class, 'submitScorecard'])->middleware('permission:recruitment.view');
Route::get('interviews/{uuid}/scorecards', [Core::class, 'scorecards'])->middleware('permission:recruitment.view');
Route::get('offers', [Core::class, 'offers'])->middleware('permission:recruitment.offer.manage|recruitment.offer.approve');
Route::post('offers', [Core::class, 'createOffer'])->middleware('permission:recruitment.offer.manage');
Route::post('offers/{uuid}/{action}', [Core::class, 'offerAction'])->whereIn('action', ['submit', 'approve', 'reject', 'send', 'accept', 'decline', 'withdraw'])
    ->middleware('permission:recruitment.offer.manage|recruitment.offer.approve');
Route::post('offers/{uuid}/handover', [Core::class, 'handover'])->middleware('permission:recruitment.handover');
Route::get('analytics', [Core::class, 'analytics'])->middleware('permission:recruitment.reports.view');
