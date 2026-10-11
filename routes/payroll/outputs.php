<?php

use App\Http\Controllers\Payroll\Outputs\AccountingController as Accounting;
use App\Http\Controllers\Payroll\Outputs\LoanController as Loans;
use App\Http\Controllers\Payroll\Outputs\PaymentController as Payments;
use App\Http\Controllers\Payroll\Outputs\PayslipController as Payslips;
use App\Http\Controllers\Payroll\Outputs\SettlementController as Settlements;
use App\Http\Controllers\Payroll\Outputs\TaxDeclarationController as TaxDecl;
use Illuminate\Support\Facades\Route;

/*
 * Workstream C (inside /api/v1/payroll): loans and advances, payslips, payments and bank files, accounting mappings/journals,
 * full and final settlements, tax declaration review. Contract: docs/api/payroll-outputs.md.
 * Literal segments are declared before {uuid} routes.
 */

// ---- loans and advances
Route::get('loans', [Loans::class, 'index'])->middleware('permission:payroll.loans.manage|payroll.loans.approve');
Route::post('loans', [Loans::class, 'store'])->middleware('permission:payroll.loans.manage');
Route::get('advances', [Loans::class, 'advances'])->middleware('permission:payroll.loans.manage|payroll.loans.approve');
Route::post('advances', [Loans::class, 'storeAdvance'])->middleware('permission:payroll.loans.manage');
Route::post('advances/{uuid}/decision', [Loans::class, 'decideAdvance'])->middleware('permission:payroll.loans.approve');
Route::get('loans/{uuid}', [Loans::class, 'show'])->middleware('permission:payroll.loans.manage|payroll.loans.approve');
Route::post('loans/{uuid}/decision', [Loans::class, 'decide'])->middleware('permission:payroll.loans.approve');
Route::post('loans/{uuid}/adjust', [Loans::class, 'adjust'])->middleware('permission:payroll.loans.approve');

// ---- payslips (admin side)
Route::post('runs/{uuid}/payslips/generate', [Payslips::class, 'generate'])->middleware('permission:payroll.payslips.manage');
Route::post('runs/{uuid}/payslips/publish', [Payslips::class, 'publish'])->middleware('permission:payroll.payslips.manage');
Route::get('runs/{uuid}/payslips', [Payslips::class, 'index'])->middleware('permission:payroll.payslips.manage');
Route::get('payslips/{uuid}', [Payslips::class, 'show'])->middleware('permission:payroll.payslips.manage');
Route::post('payslips/{uuid}/publish', [Payslips::class, 'publishOne'])->middleware('permission:payroll.payslips.manage');
Route::post('payslips/{uuid}/withdraw', [Payslips::class, 'withdraw'])->middleware('permission:payroll.payslips.manage');
Route::get('payslips/{uuid}/access-log', [Payslips::class, 'accessLog'])->middleware('permission:payroll.payslips.manage');

// ---- payments
Route::get('payment-formats', [Payments::class, 'formats'])->middleware('permission:payroll.payments.prepare|payroll.payments.approve');
Route::post('bank-verifications', [Payments::class, 'verifyBank'])->middleware('permission:payroll.payments.prepare');
Route::get('bank-files/{uuid}/download', [Payments::class, 'download'])->middleware('permission:payroll.payments.prepare');
Route::get('payments', [Payments::class, 'index'])->middleware('permission:payroll.payments.prepare|payroll.payments.approve|payroll.payments.confirm');
Route::post('runs/{uuid}/payments', [Payments::class, 'prepare'])->middleware('permission:payroll.payments.prepare');
Route::get('payments/{uuid}', [Payments::class, 'show'])->middleware('permission:payroll.payments.prepare|payroll.payments.approve|payroll.payments.confirm');
Route::post('payments/{uuid}/approve', [Payments::class, 'approve'])->middleware('permission:payroll.payments.approve');
Route::post('payments/{uuid}/bank-file', [Payments::class, 'generateFile'])->middleware('permission:payroll.payments.prepare');
Route::post('payments/{uuid}/submit', [Payments::class, 'submit'])->middleware('permission:payroll.payments.prepare');
Route::post('payments/{uuid}/confirm', [Payments::class, 'confirm'])->middleware('permission:payroll.payments.confirm');
Route::post('payments/{uuid}/import-response', [Payments::class, 'importResponse'])->middleware('permission:payroll.payments.confirm');
Route::post('payments/{uuid}/retry', [Payments::class, 'retry'])->middleware('permission:payroll.payments.prepare');

// ---- accounting
Route::get('accounting/formats', [Accounting::class, 'formats'])->middleware('permission:payroll.accounting.manage|payroll.accounting.export');
Route::get('accounting/mappings', [Accounting::class, 'mappings'])->middleware('permission:payroll.accounting.manage|payroll.accounting.export');
Route::post('accounting/mappings', [Accounting::class, 'storeMapping'])->middleware('permission:payroll.accounting.manage');
Route::post('accounting/mappings/{uuid}/deactivate', [Accounting::class, 'deactivate'])->middleware('permission:payroll.accounting.manage');
Route::get('accounting/exports/{uuid}/download', [Accounting::class, 'download'])->middleware('permission:payroll.accounting.export');
Route::get('runs/{uuid}/journal/preview', [Accounting::class, 'preview'])->middleware('permission:payroll.accounting.manage|payroll.accounting.export');
Route::post('runs/{uuid}/journal/export', [Accounting::class, 'export'])->middleware('permission:payroll.accounting.export');
Route::get('runs/{uuid}/journal/exports', [Accounting::class, 'exports'])->middleware('permission:payroll.accounting.manage|payroll.accounting.export');

// ---- settlements
Route::get('settlements', [Settlements::class, 'index'])->middleware('permission:payroll.settlement.manage|payroll.settlement.approve');
Route::post('settlements', [Settlements::class, 'store'])->middleware('permission:payroll.settlement.manage');
Route::get('settlements/{uuid}', [Settlements::class, 'show'])->middleware('permission:payroll.settlement.manage|payroll.settlement.approve');
Route::post('settlements/{uuid}/calculate', [Settlements::class, 'recalculate'])->middleware('permission:payroll.settlement.manage');
Route::post('settlements/{uuid}/submit', [Settlements::class, 'submit'])->middleware('permission:payroll.settlement.manage');
Route::post('settlements/{uuid}/decision', [Settlements::class, 'decide'])->middleware('permission:payroll.settlement.approve');

// ---- tax declaration review
Route::post('tax-declaration-windows', [TaxDecl::class, 'window'])->middleware('permission:payroll.tax_declaration.review');
Route::get('tax-declarations', [TaxDecl::class, 'index'])->middleware('permission:payroll.tax_declaration.review');
Route::get('tax-declarations/{uuid}', [TaxDecl::class, 'show'])->middleware('permission:payroll.tax_declaration.review');
Route::post('tax-declarations/{uuid}/decision', [TaxDecl::class, 'decide'])->middleware('permission:payroll.tax_declaration.review');
