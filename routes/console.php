<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\Rules\Password;

Artisan::command('hrm:workspace {slug} {email} {--name=My Company}', function (WorkspaceProvisioner $provisioner) {
    $password = $this->secret('Administrator password (12+ characters, letters and numbers)');
    $data = ['slug' => $this->argument('slug'), 'email' => $this->argument('email'), 'password' => $password];
    $validator = validator($data, ['slug' => 'required|alpha_dash|max:100|unique:tenants,slug', 'email' => 'required|email|unique:users,email', 'password' => ['required', Password::min(12)->letters()->numbers()]]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    $provisioner->create($this->option('name'), $data['slug'], $data['email'], $password);
    $this->info('Workspace created. Sign in with your workspace slug and administrator email.');
})->purpose('Provision a tenant and its initial administrator');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// People lifecycle and hygiene (each command runs per tenant and is idempotent).
Schedule::command('people:advance-lifecycle')->hourly();
Schedule::command('people:probation-reminders')->dailyAt('06:00');
Schedule::command('people:document-expiry')->dailyAt('06:15');
Schedule::command('people:scan-quality')->dailyAt('01:00');
Schedule::command('lifecycle:remind')->dailyAt('07:00');
Schedule::command('lifecycle:housekeeping')->dailyAt('00:30');
Schedule::command('performance:remind')->dailyAt('07:30');
Schedule::command('recruitment:housekeeping')->hourly()->withoutOverlapping();
Schedule::command('attendance:process-nightly')->dailyAt('01:30');
Schedule::command('attendance:expire-requests')->dailyAt('01:00');
Schedule::command('attendance:send-reminders')->everyFifteenMinutes();
Schedule::command('experience:purge-survey-data')->dailyAt('03:00');
Schedule::command('experience:escalate-sla-tickets')->hourly()->withoutOverlapping();
Schedule::command('experience:deliver-announcements')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('experience:deliver-surveys')->hourly()->withoutOverlapping();
Schedule::command('experience:send-milestones')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('workflows:advance')->everyMinute()->withoutOverlapping();

// Leave ledger operations (workstream B): idempotent nightly accrual, then expiry + alerts (+ optional auto carry-forward).
Schedule::command('leave:accrue-nightly')->dailyAt('02:00');
Schedule::command('leave:process-expiry')->dailyAt('02:30');
