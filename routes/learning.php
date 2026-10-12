<?php

use App\Http\Controllers\Learning\LearningController as Learning;
use App\Http\Controllers\Learning\LearningOpsController as Ops;
use App\Http\Controllers\Learning\LearningSkillsReportsController as Sk;
use Illuminate\Support\Facades\Route;

/* Phase 9 lean core. Contract: docs/api/learning.md. Included in authenticated tenant routes. */
Route::prefix('v1/learning')->middleware('module:learning')->group(function () {
    Route::get('courses', [Learning::class, 'courses'])->middleware('permission:learning.self|learning.view|learning.catalog.manage');
    Route::get('courses/{uuid}', [Learning::class, 'course'])->middleware('permission:learning.self|learning.view|learning.catalog.manage');
    Route::post('courses', [Learning::class, 'createCourse'])->middleware('permission:learning.catalog.manage');
    Route::patch('courses/{uuid}', [Learning::class, 'updateCourse'])->middleware('permission:learning.catalog.manage');
    Route::post('courses/{uuid}/status', [Learning::class, 'courseStatus'])->middleware('permission:learning.catalog.manage');
    Route::get('courses/{uuid}/sessions', [Learning::class, 'sessions'])->middleware('permission:learning.self|learning.view|learning.catalog.manage');
    Route::post('courses/{uuid}/sessions', [Learning::class, 'createSession'])->middleware('permission:learning.catalog.manage');
    Route::post('courses/{uuid}/enrol', [Learning::class, 'enrol'])->middleware('permission:learning.self');
    Route::get('my', [Learning::class, 'myLearning'])->middleware('permission:learning.self');
    Route::get('paths', [Learning::class, 'paths'])->middleware('permission:learning.self|learning.view|learning.catalog.manage');
    Route::post('paths', [Learning::class, 'createPath'])->middleware('permission:learning.catalog.manage');
    Route::get('skills', [Learning::class, 'skills'])->middleware('permission:learning.self|learning.view|learning.skills.manage');
    Route::get('skill-levels', [Learning::class, 'levels'])->middleware('permission:learning.self|learning.view|learning.skills.manage');
    Route::get('my/skills', [Learning::class, 'mySkills'])->middleware('permission:learning.self');
    Route::post('my/skills', [Learning::class, 'declareSkill'])->middleware('permission:learning.self');
    // Phase 9 completion: assignment, decisions, attendance, compliance, assessments, certificates, skills admin, reports.
    Route::get('lookups', [Ops::class, 'lookups'])->middleware('permission:learning.enrol.manage|learning.assign.manage|learning.skills.manage');
    Route::get('enrolments', [Ops::class, 'enrolments'])->middleware('permission:learning.enrol.manage|learning.enrol.approve|learning.view');
    Route::post('assign/course', [Ops::class, 'assignCourse'])->middleware('permission:learning.enrol.manage');
    Route::post('assign/path', [Ops::class, 'assignPath'])->middleware('permission:learning.enrol.manage');
    Route::post('enrolments/{uuid}/decision', [Ops::class, 'decide'])->middleware('permission:learning.enrol.approve');
    Route::post('enrolments/{uuid}/attendance', [Ops::class, 'attendance'])->middleware('permission:learning.enrol.manage');
    Route::post('enrolments/{uuid}/complete', [Ops::class, 'complete'])->middleware('permission:learning.enrol.manage');
    Route::post('enrolments/{uuid}/cancel', [Ops::class, 'cancelEnrolment'])->middleware('permission:learning.self|learning.enrol.manage');
    Route::post('sessions/{uuid}/cancel', [Ops::class, 'cancelSession'])->middleware('permission:learning.catalog.manage');

    Route::get('compliance/rules', [Ops::class, 'rules'])->middleware('permission:learning.assign.manage|learning.view');
    Route::post('compliance/rules', [Ops::class, 'createRule'])->middleware('permission:learning.assign.manage');
    Route::patch('compliance/rules/{uuid}', [Ops::class, 'updateRule'])->middleware('permission:learning.assign.manage');
    Route::post('compliance/run', [Ops::class, 'runCompliance'])->middleware('permission:learning.assign.manage');
    Route::get('compliance/status', [Ops::class, 'complianceStatus'])->middleware('permission:learning.assign.manage|learning.view');
    Route::get('compliance/assignments', [Ops::class, 'assignments'])->middleware('permission:learning.assign.manage|learning.view');
    Route::post('compliance/assignments/{uuid}/waive', [Ops::class, 'waive'])->middleware('permission:learning.assign.manage');
    Route::get('my/assignments', [Ops::class, 'myAssignments'])->middleware('permission:learning.self');

    Route::get('courses/{uuid}/assessments', [Ops::class, 'assessments'])->middleware('permission:learning.catalog.manage');
    Route::post('courses/{uuid}/assessments', [Ops::class, 'createAssessment'])->middleware('permission:learning.catalog.manage');
    Route::get('courses/{uuid}/assessment', [Ops::class, 'takeAssessment'])->middleware('permission:learning.self');
    Route::post('assessments/{uuid}/attempts', [Ops::class, 'submitAssessment'])->middleware('permission:learning.self');

    Route::get('certifications', [Ops::class, 'certifications'])->middleware('permission:learning.catalog.manage|learning.view');
    Route::post('certifications', [Ops::class, 'createCertification'])->middleware('permission:learning.catalog.manage');
    Route::patch('certifications/{uuid}', [Ops::class, 'updateCertification'])->middleware('permission:learning.catalog.manage');
    Route::get('my/certificates', [Ops::class, 'myCertificates'])->middleware('permission:learning.self');
    Route::get('certificates', [Ops::class, 'certificates'])->middleware('permission:learning.view');
    Route::get('certificates/{uuid}/print', [Ops::class, 'printCertificate'])->middleware('permission:learning.self|learning.view');

    Route::get('skill-categories', [Sk::class, 'categories'])->middleware('permission:learning.self|learning.view|learning.skills.manage');
    Route::post('skill-categories', [Sk::class, 'saveCategory'])->middleware('permission:learning.skills.manage');
    Route::patch('skill-categories/{uuid}', [Sk::class, 'saveCategory'])->middleware('permission:learning.skills.manage');
    Route::post('skills', [Sk::class, 'saveSkill'])->middleware('permission:learning.skills.manage');
    Route::patch('skills/{uuid}', [Sk::class, 'saveSkill'])->middleware('permission:learning.skills.manage');
    Route::put('skill-levels', [Sk::class, 'saveLevels'])->middleware('permission:learning.skills.manage');
    Route::get('skill-requirements', [Sk::class, 'requirements'])->middleware('permission:learning.skills.manage|learning.view');
    Route::put('skill-requirements', [Sk::class, 'saveRequirement'])->middleware('permission:learning.skills.manage');
    Route::delete('skill-requirements/{uuid}', [Sk::class, 'deleteRequirement'])->middleware('permission:learning.skills.manage');
    Route::get('skill-gaps', [Sk::class, 'skillGaps'])->middleware('permission:learning.view');
    Route::get('employees/{uuid}/skills', [Sk::class, 'employeeSkills'])->middleware('permission:learning.self|learning.view');
    Route::get('employees/{uuid}/skill-gap', [Sk::class, 'employeeGap'])->middleware('permission:learning.self|learning.view');
    Route::post('employees/{uuid}/skills/{skillUuid}/validate', [Sk::class, 'validateSkill'])->middleware('permission:learning.skills.validate');
    Route::get('employees/{uuid}/skills/{skillUuid}/evidence', [Sk::class, 'skillEvidence'])->middleware('permission:learning.skills.validate');

    Route::get('dashboard', [Sk::class, 'dashboard'])->middleware('permission:learning.reports.view|learning.view');
    Route::get('command-center', [Sk::class, 'commandCenter'])->middleware('permission:learning.reports.view');
    Route::get('reports/export', [Sk::class, 'export'])->middleware('permission:learning.reports.export');
});
