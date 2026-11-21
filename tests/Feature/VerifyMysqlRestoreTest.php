<?php

namespace Tests\Feature;

use Tests\TestCase;

final class VerifyMysqlRestoreTest extends TestCase
{
    public function test_application_schema_is_rejected_before_connecting(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'hrm_restore_same',
            'database.restore_verification' => [
                'host' => '127.0.0.1', 'port' => 33307, 'database' => 'hrm_restore_same',
                'username' => 'restore_reader', 'password' => '',
            ],
        ]);

        $this->artisan('backup:verify-mysql')
            ->expectsOutputToContain('Configure a separate restore schema')->assertFailed();
    }

    public function test_connection_options_cannot_inject_dsn_segments(): void
    {
        config(['database.restore_verification' => [
            'host' => '127.0.0.1;dbname=hrm', 'port' => 33307, 'database' => 'hrm_restore_test',
            'username' => 'restore_reader', 'password' => '',
        ]]);

        $this->artisan('backup:verify-mysql')
            ->expectsOutputToContain('Configure an explicit restore host')->assertFailed();
    }

    public function test_negative_expected_counts_are_rejected_before_connecting(): void
    {
        config(['database.restore_verification' => [
            'host' => '127.0.0.1', 'port' => 33307, 'database' => 'hrm_restore_test',
            'username' => 'restore_reader', 'password' => '',
        ]]);

        $this->artisan('backup:verify-mysql', ['--expect-users' => '-1'])
            ->expectsOutputToContain('Expected counts must be nonnegative integers')->assertFailed();
    }
}
