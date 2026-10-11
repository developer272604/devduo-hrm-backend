<?php

use Illuminate\Support\Facades\Route;

/* Leave & absence routes (Phase 4). One file per workstream under routes/leave/; each owns its own controllers namespace. */
Route::prefix('v1/leave')->middleware('module:leave')->group(function () {
    foreach (['policies', 'ledger', 'requests', 'operations'] as $file) {
        require __DIR__.'/leave/'.$file.'.php';
    }
});
