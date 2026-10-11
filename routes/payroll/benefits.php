<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Payroll\Benefits\BenefitController;

/*
 * Workstream C (inside /api/v1/benefits): benefit plans, providers, eligibility, enrollment windows, enrollments (admin + me).
 * Permissions: payroll.benefits.manage, payroll.benefits.view_own, payroll.benefits.enroll_own.
 * Controllers: App\Http\Controllers\Payroll\Benefits\BenefitController.
 */
Route::get('plans', [BenefitController::class, 'plans'])->middleware('permission:payroll.benefits.manage');
Route::post('plans', [BenefitController::class, 'createPlan'])->middleware('permission:payroll.benefits.manage');
Route::post('plans/{uuid}/activate', [BenefitController::class, 'activate'])->middleware('permission:payroll.benefits.manage');
Route::post('plans/{uuid}/windows', [BenefitController::class, 'window'])->middleware('permission:payroll.benefits.manage');
Route::get('available', [BenefitController::class, 'openPlans'])->middleware('permission:payroll.benefits.view_own|payroll.benefits.enroll_own');
Route::post('plans/{uuid}/enroll', [BenefitController::class, 'enroll'])->middleware('permission:payroll.benefits.enroll_own');
Route::get('me', [BenefitController::class, 'own'])->middleware('permission:payroll.benefits.view_own');
Route::post('me/{uuid}/termination', [BenefitController::class, 'requestTermination'])->middleware('permission:payroll.benefits.view_own');
Route::get('enrollments', [BenefitController::class, 'enrollments'])->middleware('permission:payroll.benefits.manage');
Route::post('enrollments/{uuid}/decision', [BenefitController::class, 'decide'])->middleware('permission:payroll.benefits.manage');
Route::post('enrollments/{uuid}/termination-decision', [BenefitController::class, 'decideTermination'])->middleware('permission:payroll.benefits.manage');
