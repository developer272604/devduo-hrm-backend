<?php

use App\Http\Controllers\People\EmployeeController;
use Illuminate\Support\Facades\Route;

/*
 | People domain routes, included inside the authenticated, tenant-scoped /api group (see web.php).
 | Each workstream owns exactly one file under routes/people/ to keep parallel work conflict-free.
 */
Route::prefix('v1')->middleware('module:people')->group(function () {
    Route::get('me/employee', [EmployeeController::class, 'mine']);
    Route::get('directory', [EmployeeController::class, 'coworkers'])->middleware('permission:people.directory');
    Route::get('employees', [EmployeeController::class, 'index'])->middleware('permission:people.view');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:people.manage');
    Route::post('employees/quick-add', [EmployeeController::class, 'quickAdd'])->middleware('permission:people.manage');
    Route::get('people/numbering/preview', [EmployeeController::class, 'numberingPreview'])->middleware('permission:people.manage');
    Route::get('employees/{uuid}', [EmployeeController::class, 'show'])->middleware('permission:people.view|people.directory');
    Route::patch('employees/{uuid}/employment', [EmployeeController::class, 'updateEmployment'])->middleware('permission:people.manage');
    Route::patch('employees/{uuid}/personal', [EmployeeController::class, 'updatePersonal'])->middleware('permission:people.view');
    Route::get('employees/{uuid}/assignments', [EmployeeController::class, 'assignments'])->middleware('permission:people.view');
    Route::post('employees/{uuid}/assignments', [EmployeeController::class, 'changeAssignment'])->middleware('permission:people.manage');
    Route::get('employees/{uuid}/relationships', [EmployeeController::class, 'relationships'])->middleware('permission:people.view');
    Route::post('employees/{uuid}/relationships', [EmployeeController::class, 'changeRelationship'])->middleware('permission:people.manage');
    Route::get('employees/{uuid}/status', [EmployeeController::class, 'statusHistory'])->middleware('permission:people.view');
    Route::post('employees/{uuid}/status', [EmployeeController::class, 'changeStatus'])->middleware('permission:people.manage');

    foreach (['sections', 'records', 'workflows', 'operations', 'insights', 'lifecycle'] as $file) {
        require __DIR__.'/people/'.$file.'.php';
    }
});
