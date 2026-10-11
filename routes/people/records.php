<?php

use App\Http\Controllers\People\Records\AddressController;
use App\Http\Controllers\People\Records\BankAccountController;
use App\Http\Controllers\People\Records\CountryController;
use App\Http\Controllers\People\Records\DocumentController;
use App\Http\Controllers\People\Records\DocumentTypeController;
use App\Http\Controllers\People\Records\IdentityController;
use App\Http\Controllers\People\Records\PhotoController;
use App\Http\Controllers\People\Records\WorkAuthorizationController;
use Illuminate\Support\Facades\Route;

// Routes for the records workstream (inside prefix v1, module:people).
// Route middleware is only the coarse gate; subject reach (data scope), field access and edit rules are enforced in the controllers.

Route::get('people/countries', [CountryController::class, 'index'])->middleware('permission:people.view|people.directory');
Route::get('people/countries/{code}', [CountryController::class, 'show'])->middleware('permission:people.view|people.directory');
Route::put('people/countries/{code}', [CountryController::class, 'update'])->middleware('permission:people.settings');
Route::delete('people/countries/{code}', [CountryController::class, 'destroy'])->middleware('permission:people.settings');

Route::get('people/document-types', [DocumentTypeController::class, 'index'])->middleware('permission:people.view');
Route::post('people/document-types', [DocumentTypeController::class, 'store'])->middleware('permission:people.settings');
Route::patch('people/document-types/{code}', [DocumentTypeController::class, 'update'])->middleware('permission:people.settings');
Route::delete('people/document-types/{code}', [DocumentTypeController::class, 'destroy'])->middleware('permission:people.settings');

Route::prefix('employees/{uuid}')->group(function () {
    Route::get('addresses', [AddressController::class, 'index'])->middleware('permission:people.view');
    Route::post('addresses', [AddressController::class, 'store'])->middleware('permission:people.view');
    Route::patch('addresses/{id}', [AddressController::class, 'update'])->middleware('permission:people.view');
    Route::delete('addresses/{id}', [AddressController::class, 'destroy'])->middleware('permission:people.view');

    Route::get('documents', [DocumentController::class, 'index'])->middleware('permission:people.view');
    Route::get('documents/requirements', [DocumentController::class, 'requirements'])->middleware('permission:people.view');
    Route::post('documents', [DocumentController::class, 'store'])->middleware('permission:people.view')->middleware('throttle:30,1');
    Route::get('documents/{id}', [DocumentController::class, 'show'])->middleware('permission:people.view');
    Route::patch('documents/{id}', [DocumentController::class, 'update'])->middleware('permission:people.view');
    Route::post('documents/{id}/versions', [DocumentController::class, 'addVersion'])->middleware('permission:people.view')->middleware('throttle:30,1');
    Route::get('documents/{id}/download', [DocumentController::class, 'download'])->middleware('permission:people.view');
    Route::post('documents/{id}/verify', [DocumentController::class, 'verify'])->middleware('permission:people.documents.verify');
    Route::delete('documents/{id}', [DocumentController::class, 'destroy'])->middleware('permission:people.documents.manage');

    Route::get('identity', [IdentityController::class, 'index'])->middleware('permission:people.view');
    Route::post('identity', [IdentityController::class, 'store'])->middleware('permission:people.view');
    Route::patch('identity/{id}', [IdentityController::class, 'update'])->middleware('permission:people.view');
    Route::delete('identity/{id}', [IdentityController::class, 'destroy'])->middleware('permission:people.view');
    Route::post('identity/{id}/verify', [IdentityController::class, 'verify'])->middleware('permission:people.documents.verify');
    Route::post('identity/{id}/reveal', [IdentityController::class, 'reveal'])->middleware('permission:people.sensitive.reveal')->middleware('throttle:30,1');

    Route::get('work-authorizations', [WorkAuthorizationController::class, 'index'])->middleware('permission:people.view');
    Route::post('work-authorizations', [WorkAuthorizationController::class, 'store'])->middleware('permission:people.view');
    Route::patch('work-authorizations/{id}', [WorkAuthorizationController::class, 'update'])->middleware('permission:people.view');
    Route::delete('work-authorizations/{id}', [WorkAuthorizationController::class, 'destroy'])->middleware('permission:people.view');
    Route::post('work-authorizations/{id}/verify', [WorkAuthorizationController::class, 'verify'])->middleware('permission:people.documents.verify');
    Route::post('work-authorizations/{id}/reveal', [WorkAuthorizationController::class, 'reveal'])->middleware('permission:people.sensitive.reveal')->middleware('throttle:30,1');

    Route::get('bank-accounts', [BankAccountController::class, 'index'])->middleware('permission:people.view');
    Route::post('bank-accounts', [BankAccountController::class, 'store'])->middleware('permission:people.view');
    Route::get('bank-accounts/{id}', [BankAccountController::class, 'show'])->middleware('permission:people.view');
    Route::patch('bank-accounts/{id}', [BankAccountController::class, 'update'])->middleware('permission:people.view');
    Route::post('bank-accounts/{id}/retire', [BankAccountController::class, 'retire'])->middleware('permission:people.view');
    Route::post('bank-accounts/{id}/reveal', [BankAccountController::class, 'reveal'])->middleware('permission:people.sensitive.reveal')->middleware('throttle:30,1');

    Route::get('photo', [PhotoController::class, 'show'])->middleware('permission:people.view|people.directory');
    Route::post('photo', [PhotoController::class, 'store'])->middleware('permission:people.view');
    Route::delete('photo', [PhotoController::class, 'destroy'])->middleware('permission:people.view');
});
