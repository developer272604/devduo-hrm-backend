<?php

use App\Http\Controllers\Payroll\SelfService\SelfServiceController as Me;
use Illuminate\Support\Facades\Route;

/*
 * Workstream C (inside /api/v1/payroll/me): employee self-service. Own data only (employee resolved from the signed-in user, never from
 * request input). Payslip downloads use short-lived signed URLs. See docs/api/payroll-outputs.md.
 */
Route::prefix('me')->group(function () {
    Route::get('payslips', [Me::class, 'payslips'])->middleware('permission:payroll.payslips.view_own');
    Route::get('payslips/{uuid}', [Me::class, 'payslip'])->middleware('permission:payroll.payslips.view_own');
    Route::get('payslips/{uuid}/print', [Me::class, 'print'])->middleware('permission:payroll.payslips.view_own');
    Route::post('payslips/{uuid}/download-link', [Me::class, 'downloadLink'])->middleware('permission:payroll.payslips.view_own');
    Route::get('payslips/{uuid}/download', [Me::class, 'download'])->name('payroll.me.payslip.download')->middleware(['signed:relative', 'permission:payroll.payslips.view_own']);
    Route::get('compensation', [Me::class, 'compensation'])->middleware('permission:payroll.compensation.view_own');
    Route::get('loans', [Me::class, 'loans'])->middleware('permission:payroll.loans.view_own');
    Route::get('tax-declaration-windows', [Me::class, 'taxPacks'])->middleware('permission:payroll.tax_declaration.submit');
    Route::get('tax-declaration-schema', [Me::class, 'taxSchema'])->middleware('permission:payroll.tax_declaration.submit');
    Route::get('tax-declarations', [Me::class, 'declarations'])->middleware('permission:payroll.tax_declaration.submit');
    Route::post('tax-declarations', [Me::class, 'submitDeclaration'])->middleware('permission:payroll.tax_declaration.submit');
    Route::post('tax-declarations/{uuid}/proofs', [Me::class, 'addProof'])->middleware('permission:payroll.tax_declaration.submit');
});
