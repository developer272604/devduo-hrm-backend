<?php

use App\Http\Controllers\People\Records\LetterController;
use Illuminate\Support\Facades\Route;

Route::get('people/letter-templates', [LetterController::class, 'templateList'])->middleware('permission:people.documents.manage');
Route::post('people/letter-templates', [LetterController::class, 'saveTemplate'])->middleware('permission:people.documents.manage');
Route::get('employees/{uuid}/letters', [LetterController::class, 'index'])->middleware('permission:people.view|people.documents.manage');
Route::post('employees/{uuid}/letters', [LetterController::class, 'issue'])->middleware('permission:people.documents.manage');
Route::get('employees/{uuid}/letters/{letter}/download', [LetterController::class, 'download'])->whereUuid('letter')->middleware('permission:people.view|people.documents.manage');
Route::post('employees/{uuid}/letters/{letter}/acknowledge', [LetterController::class, 'acknowledge'])->whereUuid('letter')->middleware('permission:people.view');
Route::post('employees/{uuid}/letters/{letter}/sign', [LetterController::class, 'sign'])->whereUuid('letter')->middleware('permission:people.view');
