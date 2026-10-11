<?php

use Illuminate\Support\Facades\Route;

/*
 * Payroll, compensation and benefits routes (Phase 5). One file per workstream under routes/payroll/; each owns its controllers namespace
 * (App\Http\Controllers\Payroll\<Area>). Everything is module-gated (`module:payroll`); add `permission:` middleware per route.
 *
 *   /api/v1/payroll       config (A) · runs (B) · outputs (C) · self (C) · operations (D)
 *   /api/v1/compensation  compensation (A): structures, components, revisions, mass revisions
 *   /api/v1/benefits      benefits (C): plans, windows, enrollments, own benefits
 */
Route::middleware('module:payroll')->group(function () {
    Route::prefix('v1/payroll')->group(function () {
        foreach (['config', 'runs', 'outputs', 'self', 'operations'] as $file) {
            require __DIR__.'/payroll/'.$file.'.php';
        }
    });
    Route::prefix('v1/compensation')->group(function () {
        require __DIR__.'/payroll/compensation.php';
    });
    Route::prefix('v1/benefits')->group(function () {
        require __DIR__.'/payroll/benefits.php';
    });
});
