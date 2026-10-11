<?php

use App\Http\Controllers\People\Lifecycle\ChangeRequestController;
use App\Http\Controllers\People\Lifecycle\InvitationController;
use App\Http\Controllers\People\Lifecycle\ProbationController;
use App\Http\Controllers\People\Lifecycle\SeparationController;
use Illuminate\Support\Facades\Route;

// Employee lifecycle (inside prefix v1, module:people): change requests, invitations, probation, separation, rehire, retention.
Route::get('employees/{uuid}/change-requests', [ChangeRequestController::class, 'forEmployee'])->middleware('permission:people.view');
Route::post('employees/{uuid}/change-requests', [ChangeRequestController::class, 'store'])->middleware('permission:people.view');
Route::get('people/change-requests', [ChangeRequestController::class, 'index'])->middleware('permission:people.view');
Route::get('people/change-requests/{uuid}', [ChangeRequestController::class, 'show'])->middleware('permission:people.view');
Route::post('people/change-requests/{uuid}/approve', [ChangeRequestController::class, 'approve'])->middleware('permission:people.changes.approve');
Route::post('people/change-requests/{uuid}/reject', [ChangeRequestController::class, 'reject'])->middleware('permission:people.changes.approve');
Route::post('people/change-requests/{uuid}/cancel', [ChangeRequestController::class, 'cancel'])->middleware('permission:people.view');

Route::get('employees/{uuid}/invitations', [InvitationController::class, 'index'])->middleware('permission:people.invite');
Route::post('employees/{uuid}/invitations', [InvitationController::class, 'store'])->middleware('permission:people.invite');
Route::post('people/invitations/{uuid}/resend', [InvitationController::class, 'resend'])->middleware('permission:people.invite');
Route::post('people/invitations/{uuid}/cancel', [InvitationController::class, 'cancel'])->middleware('permission:people.invite');

Route::post('employees/{uuid}/probation/confirm', [ProbationController::class, 'confirm'])->middleware('permission:people.view');
Route::post('employees/{uuid}/probation/extend', [ProbationController::class, 'extend'])->middleware('permission:people.view');

Route::get('employees/{uuid}/separations', [SeparationController::class, 'index'])->middleware('permission:people.separate');
Route::post('employees/{uuid}/separation', [SeparationController::class, 'store'])->middleware('permission:people.separate');
Route::post('employees/{uuid}/separation/complete', [SeparationController::class, 'complete'])->middleware('permission:people.separate');
Route::post('employees/{uuid}/separation/withdraw', [SeparationController::class, 'withdraw'])->middleware('permission:people.separate');
Route::post('employees/{uuid}/rehire', [SeparationController::class, 'rehire'])->middleware('permission:people.rehire');
Route::get('employees/{uuid}/retention', [SeparationController::class, 'retention'])->middleware('permission:people.separate');
Route::put('employees/{uuid}/retention', [SeparationController::class, 'setRetention'])->middleware('permission:people.settings');
Route::post('employees/{uuid}/anonymize', [SeparationController::class, 'anonymize'])->middleware('permission:people.settings');
