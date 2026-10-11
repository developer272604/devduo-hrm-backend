<?php

use App\Http\Controllers\People\Sections\CatalogController;
use App\Http\Controllers\People\Sections\CertificationController;
use App\Http\Controllers\People\Sections\ContactController;
use App\Http\Controllers\People\Sections\DependentController;
use App\Http\Controllers\People\Sections\EducationController;
use App\Http\Controllers\People\Sections\EmergencyContactController;
use App\Http\Controllers\People\Sections\EmployeeSkillController;
use App\Http\Controllers\People\Sections\EmployeeTagController;
use App\Http\Controllers\People\Sections\ExperienceController;
use App\Http\Controllers\People\Sections\NoteController;
use Illuminate\Support\Facades\Route;

// Routes for the sections workstream (inside prefix v1, module:people).
//
// Personal profile sections. Reads need people.view; writes also only need people.view at the route because
// self-service edits are allowed: the real decision (direct | change request | forbidden) is FieldAccess::editGroup,
// evaluated server-side in the controllers. Verification, notes, tags and catalogs have their own permissions.

foreach ([
    'contacts' => ContactController::class,
    'emergency-contacts' => EmergencyContactController::class,
    'dependents' => DependentController::class,
    'education' => EducationController::class,
    'experience' => ExperienceController::class,
    'certifications' => CertificationController::class,
] as $segment => $controller) {
    Route::get("employees/{uuid}/$segment", [$controller, 'index'])->middleware('permission:people.view');
    Route::post("employees/{uuid}/$segment", [$controller, 'store'])->middleware('permission:people.view');
    Route::patch("employees/{uuid}/$segment/{id}", [$controller, 'update'])->middleware('permission:people.view')->whereUuid('id');
    Route::delete("employees/{uuid}/$segment/{id}", [$controller, 'destroy'])->middleware('permission:people.view')->whereUuid('id');
}
Route::post('employees/{uuid}/education/{id}/verify', [EducationController::class, 'verify'])->middleware('permission:people.documents.verify')->whereUuid('id');
Route::post('employees/{uuid}/certifications/{id}/verify', [CertificationController::class, 'verify'])->middleware('permission:people.documents.verify')->whereUuid('id');

// Skills assigned to a person (addressed by catalog skill id).
Route::get('employees/{uuid}/skills', [EmployeeSkillController::class, 'index'])->middleware('permission:people.view');
Route::post('employees/{uuid}/skills', [EmployeeSkillController::class, 'store'])->middleware('permission:people.view');
Route::patch('employees/{uuid}/skills/{skillId}', [EmployeeSkillController::class, 'update'])->middleware('permission:people.view')->whereNumber('skillId');
Route::delete('employees/{uuid}/skills/{skillId}', [EmployeeSkillController::class, 'destroy'])->middleware('permission:people.view')->whereNumber('skillId');
Route::post('employees/{uuid}/skills/{skillId}/verify', [EmployeeSkillController::class, 'verify'])->middleware('permission:people.documents.verify')->whereNumber('skillId');

// Notes: visibility-filtered reads; writes need people.notes.manage.
Route::get('employees/{uuid}/notes', [NoteController::class, 'index'])->middleware('permission:people.view');
Route::post('employees/{uuid}/notes', [NoteController::class, 'store'])->middleware('permission:people.notes.manage');
Route::patch('employees/{uuid}/notes/{id}', [NoteController::class, 'update'])->middleware('permission:people.notes.manage')->whereUuid('id');
Route::delete('employees/{uuid}/notes/{id}', [NoteController::class, 'destroy'])->middleware('permission:people.notes.manage')->whereUuid('id');

// Tags on an employment.
Route::get('employees/{uuid}/tags', [EmployeeTagController::class, 'index'])->middleware('permission:people.view');
Route::post('employees/{uuid}/tags/{tagId}', [EmployeeTagController::class, 'assign'])->middleware('permission:people.manage')->whereNumber('tagId');
Route::delete('employees/{uuid}/tags/{tagId}', [EmployeeTagController::class, 'unassign'])->middleware('permission:people.manage')->whereNumber('tagId');

// Tenant catalogs.
Route::get('people/skills', [CatalogController::class, 'skills'])->middleware('permission:people.view');
Route::post('people/skills', [CatalogController::class, 'createSkill'])->middleware('permission:people.settings');
Route::patch('people/skills/{id}', [CatalogController::class, 'updateSkill'])->middleware('permission:people.settings')->whereNumber('id');
Route::get('people/tags', [CatalogController::class, 'tags'])->middleware('permission:people.view');
Route::post('people/tags', [CatalogController::class, 'createTag'])->middleware('permission:people.settings');
Route::patch('people/tags/{id}', [CatalogController::class, 'updateTag'])->middleware('permission:people.settings')->whereNumber('id');
