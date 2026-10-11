<?php

use App\Http\Controllers\Payroll\Operations\OperationsController as Ops;
use Illuminate\Support\Facades\Route;

/*
 * Workstream D (inside /api/v1/payroll): readiness (+ map), command center, explainer, difference views, simulations, reports/exports, sensitive-access log.
 * Controllers: App\Http\Controllers\Payroll\Operations. Documented in docs/api/payroll-operations.md.
 */
$view = 'permission:payroll.view|payroll.run|payroll.configure|payroll.results.view|payroll.compensation.view';

Route::get('readiness', [Ops::class, 'readiness'])->middleware('permission:payroll.configure|payroll.compensation.view|payroll.view|payroll.run|payroll.results.view');
Route::get('readiness/map', [Ops::class, 'readinessMap'])->middleware($view);
Route::get('command-center', [Ops::class, 'commandCenter'])->middleware($view);

Route::get('explain/employees/{employee}', [Ops::class, 'explain'])->middleware('permission:payroll.explain');
Route::get('difference/employees/{employee}', [Ops::class, 'employeeDifference'])->middleware('permission:payroll.explain');
Route::get('difference/runs/{run}', [Ops::class, 'runDifference'])->middleware('permission:payroll.results.view');

Route::post('simulate', [Ops::class, 'simulate'])->middleware('permission:payroll.simulate');
Route::get('simulations', [Ops::class, 'simulations'])->middleware('permission:payroll.simulate');
Route::post('simulations', [Ops::class, 'simulationStore'])->middleware('permission:payroll.simulate');
Route::get('simulations/{uuid}', [Ops::class, 'simulation'])->middleware('permission:payroll.simulate');

Route::get('reports', [Ops::class, 'reports'])->middleware('permission:payroll.reports.view');
Route::get('reports/{report}', [Ops::class, 'report'])->middleware('permission:payroll.reports.view');
Route::post('reports/{report}/export', [Ops::class, 'export'])->middleware('permission:payroll.export');
Route::get('exports', [Ops::class, 'exports'])->middleware('permission:payroll.export');
Route::get('exports/{uuid}/download', [Ops::class, 'download'])->middleware('permission:payroll.export');

Route::get('sensitive-access', [Ops::class, 'sensitiveAccess'])->middleware('permission:payroll.configure');
Route::get('sensitive-access/purposes', [Ops::class, 'purposes'])->middleware($view);
