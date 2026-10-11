<?php

use App\Http\Controllers\AccessController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WorkController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['app' => 'devduo HRM', 'status' => 'ok']));
// REST endpoints deliberately use the web middleware for encrypted sessions and CSRF protection.
Route::prefix('api')->group(function () {
    // Device ingestion (token-authenticated, tenant resolved from the device): see routes/device.php.
    Route::prefix('device/v1')->middleware(['device', 'throttle:600,1'])->group(__DIR__.'/device.php');
    Route::prefix('public/v1')->group(__DIR__.'/integrations_public.php');
    Route::get('csrf', [AuthController::class, 'csrf']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::post('login/mfa', [AuthController::class, 'loginMfa'])->middleware('throttle:20,1');
    Route::post('forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
    Route::middleware(['auth', 'tenant', 'throttle:120,1'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        require __DIR__.'/people.php';
        require __DIR__.'/attendance.php';
        require __DIR__.'/leave.php';
        require __DIR__.'/payroll.php';
        require __DIR__.'/recruitment.php';
        require __DIR__.'/lifecycle.php';
        require __DIR__.'/performance.php';
        require __DIR__.'/learning.php';
        require __DIR__.'/experience.php';
        require __DIR__.'/assets.php';
        require __DIR__.'/expenses.php';
        require __DIR__.'/travel.php';
        require __DIR__.'/workflows.php';
        require __DIR__.'/analytics.php';
        require __DIR__.'/integrations.php';
        require __DIR__.'/security.php';
        require __DIR__.'/intelligence.php';
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'change'])->middleware('throttle:5,1');
        Route::get('dashboard', [FeedController::class, 'dashboard']);
        Route::get('organization/{type}', [OrganizationController::class, 'index'])->middleware('permission:organization.view');
        Route::post('organization/{type}', [OrganizationController::class, 'save'])->middleware('permission:organization.manage');
        Route::put('organization/{type}/{id}', [OrganizationController::class, 'save'])->middleware('permission:organization.manage');
        Route::delete('organization/{type}/{id}', [OrganizationController::class, 'destroy'])->middleware('permission:organization.manage');
        Route::get('users', [AccessController::class, 'users'])->middleware('permission:users.view');
        Route::post('users', [AccessController::class, 'saveUser'])->middleware('permission:users.manage');
        Route::put('users/{id}', [AccessController::class, 'saveUser'])->middleware('permission:users.manage');
        Route::get('roles', [AccessController::class, 'roles'])->middleware('permission:users.view|users.manage|roles.manage');
        Route::get('permissions', [AccessController::class, 'permissions'])->middleware('permission:roles.manage');
        Route::post('roles', [AccessController::class, 'saveRole'])->middleware('permission:roles.manage');
        Route::put('roles/{id}', [AccessController::class, 'saveRole'])->middleware('permission:roles.manage');
        Route::delete('roles/{id}', [AccessController::class, 'deleteRole'])->middleware('permission:roles.manage');
        Route::get('settings', [SettingsController::class, 'show'])->middleware('permission:settings.manage');
        Route::put('settings', [SettingsController::class, 'save'])->middleware('permission:settings.manage');
        Route::put('setup', [SettingsController::class, 'setup'])->middleware('permission:settings.manage');
        Route::middleware('module:work')->group(function () {
            Route::get('work', [WorkController::class, 'index'])->middleware('permission:work.view');
            Route::post('work', [WorkController::class, 'store'])->middleware('permission:work.manage');
            Route::patch('work/{id}', [WorkController::class, 'update'])->middleware('permission:work.manage');
        });
        Route::middleware('module:notifications')->group(function () {
            Route::get('notifications', [FeedController::class, 'notifications']);
            Route::post('notifications/read-all', [FeedController::class, 'read']);
            Route::post('notifications/{id}/read', [FeedController::class, 'read']);
        });
        Route::get('audit',[FeedController::class, 'audit'])->middleware('permission:audit.view');
    });
});
