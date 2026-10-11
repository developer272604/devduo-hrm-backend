<?php

use Illuminate\Support\Facades\Route;

/* Attendance, shifts and time-office routes. One file per workstream under routes/attendance/. */
Route::prefix('v1')->middleware('module:attendance')->group(function () {
    foreach (['time', 'scheduling', 'devices', 'operations'] as $file) {
        require __DIR__.'/attendance/'.$file.'.php';
    }
});
