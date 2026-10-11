<?php

use App\Http\Controllers\Leave\Requests\ApprovalController as A;
use App\Http\Controllers\Leave\Requests\CompOffController as C;
use App\Http\Controllers\Leave\Requests\RequestController as R;
use App\Http\Controllers\Leave\Requests\TeamController as T;
use Illuminate\Support\Facades\Route;

/*
 * Leave workstream C - requests, approvals, comp-off, team calendar and availability, impact preview.
 * Included by routes/leave.php inside Route::prefix('v1/leave')->middleware('module:leave'). Controllers: App\Http\Controllers\Leave\Requests.
 */
Route::get('requests', [R::class, 'index'])->middleware('permission:leave.view|leave.team.view|leave.manage');
Route::post('requests/preview', [R::class, 'preview'])->middleware('permission:leave.request');
Route::post('requests', [R::class, 'store'])->middleware('permission:leave.request');
Route::get('requests/{uuid}', [R::class, 'show'])->middleware('permission:leave.view|leave.team.view|leave.approve|leave.manage');
Route::post('requests/{uuid}/withdraw', [R::class, 'withdraw'])->middleware('permission:leave.request');
Route::post('requests/{uuid}/cancel', [R::class, 'cancel'])->middleware('permission:leave.request|leave.approve|leave.manage');
Route::post('requests/{uuid}/modify', [R::class, 'modify'])->middleware('permission:leave.request');
Route::post('requests/{uuid}/documents', [R::class, 'addDocument'])->middleware('permission:leave.request|leave.manage');
Route::get('requests/{uuid}/documents/{document}', [R::class, 'document'])->middleware('permission:leave.view|leave.team.view|leave.approve|leave.manage');

Route::get('approvals', [A::class, 'inbox'])->middleware('permission:leave.approve|leave.manage');
Route::post('requests/{uuid}/approve', [A::class, 'approve'])->middleware('permission:leave.approve|leave.manage');
Route::post('requests/{uuid}/reject', [A::class, 'reject'])->middleware('permission:leave.approve|leave.manage');

Route::get('comp-off', [C::class, 'index'])->middleware('permission:leave.view|leave.compoff.manage');
Route::post('comp-off', [C::class, 'store'])->middleware('permission:leave.request|leave.compoff.manage');
Route::post('comp-off/{uuid}/approve', [C::class, 'approve'])->middleware('permission:leave.approve|leave.compoff.manage');
Route::post('comp-off/{uuid}/reject', [C::class, 'reject'])->middleware('permission:leave.approve|leave.compoff.manage');
Route::post('comp-off/{uuid}/claim', [C::class, 'claim'])->middleware('permission:leave.request');
Route::get('comp-off/{uuid}/trace', [C::class, 'trace'])->middleware('permission:leave.view|leave.compoff.manage');

Route::get('calendar/me', [T::class, 'mine'])->middleware('permission:leave.view');
Route::get('team/calendar', [T::class, 'calendar'])->middleware('permission:leave.team.view');
Route::get('team/availability', [T::class, 'availability'])->middleware('permission:leave.team.view');
