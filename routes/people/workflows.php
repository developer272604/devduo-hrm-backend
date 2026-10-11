<?php

use App\Http\Controllers\People\Config\CustomFieldAdminController;
use App\Http\Controllers\People\Config\DataScopeController;
use App\Http\Controllers\People\Config\EmployeeCustomFieldController;
use App\Http\Controllers\People\Config\GroupController;
use App\Http\Controllers\People\Config\LookupController;
use App\Http\Controllers\People\Config\SettingsConfigController;
use Illuminate\Support\Facades\Route;

// People admin configuration (inside prefix v1, module:people): settings, lookups, custom fields, groups, data-scope grants.
Route::get('people/settings', [SettingsConfigController::class, 'show'])->middleware('permission:people.settings');
Route::get('people/settings/preview-numbering', [SettingsConfigController::class, 'previewNumbering'])->middleware('permission:people.settings');
Route::put('people/settings/{section}', [SettingsConfigController::class, 'update'])->middleware('permission:people.settings');

Route::get('people/lookups/{kind}', [LookupController::class, 'index'])->middleware('permission:people.view');
Route::post('people/lookups/{kind}', [LookupController::class, 'store'])->middleware('permission:people.settings');
Route::put('people/lookups/{kind}/{id}', [LookupController::class, 'update'])->middleware('permission:people.settings');
Route::delete('people/lookups/{kind}/{id}', [LookupController::class, 'destroy'])->middleware('permission:people.settings');

Route::middleware('permission:people.settings|people.manage')->group(function () {
    Route::get('people/custom-fields/catalogue', [CustomFieldAdminController::class, 'catalogue']);
    Route::get('people/custom-sections', [CustomFieldAdminController::class, 'sections']);
    Route::get('people/custom-fields', [CustomFieldAdminController::class, 'fields']);
    Route::get('people/custom-fields/{id}', [CustomFieldAdminController::class, 'showField'])->whereNumber('id');
});
Route::middleware('permission:people.settings')->group(function () {
    Route::post('people/custom-sections', [CustomFieldAdminController::class, 'storeSection']);
    Route::put('people/custom-sections/{id}', [CustomFieldAdminController::class, 'updateSection']);
    Route::delete('people/custom-sections/{id}', [CustomFieldAdminController::class, 'destroySection']);
    Route::post('people/custom-fields', [CustomFieldAdminController::class, 'storeField']);
    Route::put('people/custom-fields/{id}', [CustomFieldAdminController::class, 'updateField'])->whereNumber('id');
    Route::delete('people/custom-fields/{id}', [CustomFieldAdminController::class, 'destroyField'])->whereNumber('id');
    Route::post('people/custom-fields/{id}/options', [CustomFieldAdminController::class, 'storeOption']);
    Route::put('people/custom-fields/{id}/options/{optionId}', [CustomFieldAdminController::class, 'updateOption']);
    Route::delete('people/custom-fields/{id}/options/{optionId}', [CustomFieldAdminController::class, 'destroyOption']);
});

Route::get('employees/{uuid}/custom-fields', [EmployeeCustomFieldController::class, 'show'])->middleware('permission:people.view');
Route::put('employees/{uuid}/custom-fields', [EmployeeCustomFieldController::class, 'update'])->middleware('permission:people.view');
Route::get('employees/{uuid}/custom-fields/{key}/history', [EmployeeCustomFieldController::class, 'history'])->middleware('permission:people.view');

Route::get('people/groups', [GroupController::class, 'index'])->middleware('permission:people.view');
Route::get('people/groups/{id}', [GroupController::class, 'show'])->middleware('permission:people.view');
Route::get('people/groups/{id}/members', [GroupController::class, 'members'])->middleware('permission:people.view');
Route::middleware('permission:people.settings')->group(function () {
    Route::post('people/groups', [GroupController::class, 'store']);
    Route::put('people/groups/{id}', [GroupController::class, 'update']);
    Route::delete('people/groups/{id}', [GroupController::class, 'destroy']);
    Route::post('people/groups/{id}/members', [GroupController::class, 'addMembers']);
    Route::delete('people/groups/{id}/members/{uuid}', [GroupController::class, 'removeMember']);
});

Route::get('access/data-scopes/catalogue', [DataScopeController::class, 'catalogue'])->middleware('permission:users.view|users.manage|roles.manage');
Route::get('access/data-scopes', [DataScopeController::class, 'index'])->middleware('permission:users.manage');
Route::post('access/data-scopes', [DataScopeController::class, 'store'])->middleware('permission:users.manage');
Route::delete('access/data-scopes/{id}', [DataScopeController::class, 'destroy'])->middleware('permission:users.manage');
