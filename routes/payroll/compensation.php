<?php

use App\Http\Controllers\Payroll\Compensation\CompensationController as Comp;
use Illuminate\Support\Facades\Route;

/*
 * Workstream A (inside /api/v1/compensation): salary structures, employee compensation (current / as-of / history / propose / decision),
 * revisions (preview, propose, decision, cancel, letter reference). Amounts are masked unless the viewer holds payroll.compensation.reveal.
 * See docs/api/payroll-config.md.
 */
$read = 'permission:payroll.configure|payroll.compensation.manage|payroll.compensation.view';

Route::get('structures', [Comp::class, 'structures'])->middleware($read);
Route::post('structures', [Comp::class, 'createStructure'])->middleware('permission:payroll.configure');
Route::get('structures/{uuid}', [Comp::class, 'showStructure'])->middleware($read);
Route::post('structures/{uuid}/versions', [Comp::class, 'structureVersion'])->middleware('permission:payroll.configure');
Route::post('structures/{uuid}/activate', [Comp::class, 'activateStructure'])->middleware('permission:payroll.configure');

Route::get('employees/{uuid}/compensation', [Comp::class, 'current'])->middleware('permission:payroll.compensation.view');
Route::get('employees/{uuid}/compensation/history', [Comp::class, 'history'])->middleware('permission:payroll.compensation.view');
Route::post('employees/{uuid}/compensation', [Comp::class, 'propose'])->middleware('permission:payroll.compensation.manage');
Route::post('compensations/{uuid}/decision', [Comp::class, 'decideCompensation'])->middleware('permission:payroll.compensation.approve');

Route::get('revisions', [Comp::class, 'revisions'])->middleware('permission:payroll.compensation.view');
Route::post('revisions/preview', [Comp::class, 'previewRevision'])->middleware('permission:payroll.compensation.manage');
Route::post('revision-batches/preview', [Comp::class, 'previewRevisionBatch'])->middleware('permission:payroll.compensation.manage');
Route::post('revision-batches', [Comp::class, 'submitRevisionBatch'])->middleware('permission:payroll.compensation.manage');
Route::get('revision-batches', [Comp::class, 'revisionBatches'])->middleware('permission:payroll.compensation.view');
Route::get('revision-batches/{uuid}', [Comp::class, 'showRevisionBatch'])->middleware('permission:payroll.compensation.view');
Route::post('imports/compensation/preview', [Comp::class, 'previewCompensationImport'])->middleware('permission:payroll.compensation.manage');
Route::post('imports/compensation/{uuid}/commit', [Comp::class, 'commitCompensationImport'])->middleware('permission:payroll.compensation.manage');
Route::post('revisions', [Comp::class, 'proposeRevision'])->middleware('permission:payroll.compensation.manage');
Route::get('revisions/{uuid}', [Comp::class, 'showRevision'])->middleware('permission:payroll.compensation.view');
Route::post('revisions/{uuid}/decision', [Comp::class, 'decideRevision'])->middleware('permission:payroll.compensation.approve');
Route::post('revisions/{uuid}/cancel', [Comp::class, 'cancelRevision'])->middleware('permission:payroll.compensation.manage');
Route::post('revisions/{uuid}/letter', [Comp::class, 'letter'])->middleware('permission:payroll.compensation.manage');
