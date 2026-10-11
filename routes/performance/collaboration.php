<?php

use App\Http\Controllers\Performance\CollabController as C;
use Illuminate\Support\Facades\Route;

/* Feedback, 1:1 notes, development plans and improvement plans. Required from routes/performance.php inside the v1/performance group. */
Route::post('feedback', [C::class, 'giveFeedback'])->middleware('permission:performance.self');
Route::get('feedback/received', [C::class, 'received'])->middleware('permission:performance.self');
Route::get('feedback/given', [C::class, 'given'])->middleware('permission:performance.self');
Route::get('employees/{uuid}/feedback', [C::class, 'feedbackAbout'])->middleware('permission:performance.self');
Route::post('one-on-ones', [C::class, 'recordOneOnOne'])->middleware('permission:performance.self');
Route::get('employees/{uuid}/one-on-ones', [C::class, 'oneOnOnes'])->middleware('permission:performance.self');

Route::get('development-plans', [C::class, 'plans'])->middleware('permission:performance.self|performance.view');
Route::post('development-plans', [C::class, 'createPlan'])->middleware('permission:performance.self|performance.goals.manage');
Route::post('development-plans/{uuid}/actions', [C::class, 'addAction'])->middleware('permission:performance.self|performance.goals.manage');
Route::patch('development-actions/{uuid}', [C::class, 'updateAction'])->middleware('permission:performance.self|performance.goals.manage');

Route::get('pips', [C::class, 'pipList'])->middleware('permission:performance.pip.manage');
Route::get('pips/mine', [C::class, 'pipMine'])->middleware('permission:performance.self');
Route::post('pips', [C::class, 'pipCreate'])->middleware('permission:performance.pip.manage');
Route::get('pips/{uuid}', [C::class, 'pipShow'])->middleware('permission:performance.self|performance.pip.manage');
Route::post('pips/{uuid}/activate', [C::class, 'pipActivate'])->middleware('permission:performance.pip.manage');
Route::post('pips/{uuid}/checkpoints/{cuuid}', [C::class, 'pipCheckpoint'])->middleware('permission:performance.pip.manage');
Route::post('pips/{uuid}/complete', [C::class, 'pipComplete'])->middleware('permission:performance.pip.manage');
