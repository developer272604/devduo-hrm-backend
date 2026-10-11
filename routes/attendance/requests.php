<?php

use App\Http\Controllers\Attendance\Requests\OtherRequestsController as O;
use App\Http\Controllers\Attendance\Requests\RegularizationController as R;
use Illuminate\Support\Facades\Route;

// Attendance requests and approvals (inside prefix v1, module:attendance). Decision endpoints authorize through ApproverResolver.
Route::prefix('attendance')->group(function () {
    Route::get('regularizations', [R::class, 'index'])->middleware('permission:attendance.view');
    Route::post('regularizations', [R::class, 'store'])->middleware('permission:attendance.view');
    Route::get('regularizations/{uuid}', [R::class, 'show'])->middleware('permission:attendance.view');
    Route::get('regularizations/{uuid}/attachment', [R::class, 'attachment'])->middleware('permission:attendance.view');
    Route::post('regularizations/{uuid}/approve', [R::class, 'approve'])->middleware('permission:attendance.approve|attendance.manage');
    Route::post('regularizations/{uuid}/reject', [R::class, 'reject'])->middleware('permission:attendance.approve|attendance.manage');
    Route::post('regularizations/{uuid}/withdraw', [R::class, 'withdraw'])->middleware('permission:attendance.view');

    Route::get('overtime', [O::class, 'overtimeIndex'])->middleware('permission:attendance.view');
    Route::post('overtime', [O::class, 'overtimeStore'])->middleware('permission:attendance.view');
    Route::post('overtime/{uuid}/approve', [O::class, 'overtimeApprove'])->middleware('permission:attendance.overtime.approve|attendance.manage');
    Route::post('overtime/{uuid}/reject', [O::class, 'overtimeReject'])->middleware('permission:attendance.overtime.approve|attendance.manage');
    Route::post('overtime/{uuid}/withdraw', [O::class, 'overtimeWithdraw'])->middleware('permission:attendance.view');

    Route::get('on-duty', [O::class, 'dutyIndex'])->middleware('permission:attendance.view');
    Route::post('on-duty', [O::class, 'dutyStore'])->middleware('permission:attendance.view');
    Route::get('on-duty/{uuid}/attachment', [O::class, 'dutyAttachment'])->middleware('permission:attendance.view');
    Route::post('on-duty/{uuid}/approve', [O::class, 'dutyApprove'])->middleware('permission:attendance.approve|attendance.manage');
    Route::post('on-duty/{uuid}/reject', [O::class, 'dutyReject'])->middleware('permission:attendance.approve|attendance.manage');
    Route::post('on-duty/{uuid}/withdraw', [O::class, 'dutyWithdraw'])->middleware('permission:attendance.view');

    Route::get('adjustments', [O::class, 'adjustmentIndex'])->middleware('permission:attendance.adjust|attendance.adjust.approve');
    Route::post('adjustments', [O::class, 'adjustmentStore'])->middleware('permission:attendance.adjust');
    Route::post('adjustments/{uuid}/approve', [O::class, 'adjustmentApprove'])->middleware('permission:attendance.adjust.approve');
    Route::post('adjustments/{uuid}/reject', [O::class, 'adjustmentReject'])->middleware('permission:attendance.adjust.approve');
    Route::post('adjustments/{uuid}/revert', [O::class, 'adjustmentRevert'])->middleware('permission:attendance.adjust');

    Route::get('delegations', [O::class, 'delegationIndex'])->middleware('permission:attendance.approve|attendance.overtime.approve|attendance.manage');
    Route::post('delegations', [O::class, 'delegationStore'])->middleware('permission:attendance.approve|attendance.overtime.approve|attendance.manage');
    Route::put('delegations/{uuid}', [O::class, 'delegationUpdate'])->middleware('permission:attendance.approve|attendance.overtime.approve|attendance.manage');
    Route::delete('delegations/{uuid}', [O::class, 'delegationDestroy'])->middleware('permission:attendance.approve|attendance.overtime.approve|attendance.manage');

    Route::get('approvals', [O::class, 'approvals'])->middleware('permission:attendance.approve|attendance.overtime.approve|attendance.adjust.approve|attendance.manage');

    Route::get('bulk/actions', [O::class, 'bulkActions'])->middleware('permission:attendance.bulk');
    Route::post('bulk/preview', [O::class, 'bulkPreview'])->middleware('permission:attendance.bulk');
    Route::post('bulk/apply', [O::class, 'bulkApply'])->middleware('permission:attendance.bulk');
    Route::get('bulk/runs/{uuid}', [O::class, 'bulkShow'])->middleware('permission:attendance.bulk');
});
