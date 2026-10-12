<?php

use App\Http\Controllers\Recruitment\RecruitmentDocumentController as Docs;
use Illuminate\Support\Facades\Route;

/* P06-T09 private resumes + offer documents. Required from routes/recruitment.php inside the v1/recruitment group. */
Route::get('candidates/{uuid}/resumes', [Docs::class, 'resumes'])->middleware('permission:recruitment.view');
Route::post('candidates/{uuid}/resumes', [Docs::class, 'uploadResume'])->middleware('permission:recruitment.manage');
Route::get('candidates/{uuid}/resumes/{version}/download', [Docs::class, 'downloadResume'])->whereNumber('version')->middleware('permission:recruitment.view');
Route::post('candidates/{uuid}/resumes/{version}/review', [Docs::class, 'reviewResume'])->whereNumber('version')->middleware('permission:recruitment.manage');
Route::delete('candidates/{uuid}/resumes/{version}', [Docs::class, 'removeResume'])->whereNumber('version')->middleware('permission:recruitment.manage');
Route::get('offer-templates', [Docs::class, 'templates'])->middleware('permission:recruitment.offer.manage');
Route::post('offer-templates', [Docs::class, 'createTemplate'])->middleware('permission:recruitment.offer.manage');
Route::get('offers/{uuid}/documents', [Docs::class, 'offerDocuments'])->middleware('permission:recruitment.offer.manage|recruitment.offer.approve');
Route::post('offers/{uuid}/documents', [Docs::class, 'generateOfferDocument'])->middleware('permission:recruitment.offer.manage');
Route::get('offers/{uuid}/documents/{version}/print', [Docs::class, 'printOfferDocument'])->whereNumber('version')->middleware('permission:recruitment.offer.manage|recruitment.offer.approve');
