<?php

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

final class VerifySqliteBackupTest extends TestCase
{
    public function test_valid_backup_is_verified_without_changing_source(): void
    {
        $snapshot = tempnam(sys_get_temp_dir(), 'hrm-backup-test-');

        try {
            $this->createSnapshot($snapshot);
            $hash = hash_file('sha256', $snapshot);
            $this->artisan('backup:verify-sqlite', ['snapshot' => $snapshot])
                ->expectsOutput(json_encode(['status' => 'verified', 'engine' => 'sqlite', 'sha256' => $hash, 'tables' => 3], JSON_THROW_ON_ERROR))
                ->assertSuccessful();
            $this->assertSame($hash, hash_file('sha256', $snapshot));
        } finally {
            unlink($snapshot);
        }
    }

    public function test_live_sidecars_corrupt_files_and_orphaned_rows_are_rejected(): void
    {
        $snapshot = tempnam(sys_get_temp_dir(), 'hrm-backup-test-');

        try {
            file_put_contents($snapshot, 'invalid SQLite fixture');
            $this->artisan('backup:verify-sqlite', ['snapshot' => $snapshot])->assertFailed();
            file_put_contents($snapshot, '');
            $this->createSnapshot($snapshot, orphan: true);
            $this->artisan('backup:verify-sqlite', ['snapshot' => $snapshot])
                ->expectsOutputToContain('foreign-key checks failed')->assertFailed();
            touch($snapshot.'-wal');
            $this->artisan('backup:verify-sqlite', ['snapshot' => $snapshot])
                ->expectsOutputToContain('standalone backup')->assertFailed();
        } finally {
            if (is_file($snapshot.'-wal')) {
                unlink($snapshot.'-wal');
            }
            unlink($snapshot);
        }
    }

    public function test_unrelated_sqlite_file_cannot_pass_hrm_schema_verification(): void
    {
        $snapshot = tempnam(sys_get_temp_dir(), 'hrm-backup-test-');

        try {
            $db = new PDO('sqlite:'.$snapshot);
            $db->exec('CREATE TABLE unrelated (id INTEGER PRIMARY KEY)');
            $db = null;
            $this->artisan('backup:verify-sqlite', ['snapshot' => $snapshot])
                ->expectsOutputToContain('expected HRM schema')->assertFailed();
        } finally {
            unlink($snapshot);
        }
    }

    private function createSnapshot(string $path, bool $orphan = false): void
    {
        $db = new PDO('sqlite:'.$path);
        $db->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER REFERENCES tenants(id))');
        $db->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT)');
        $db->exec("INSERT INTO migrations VALUES (1, '2026_10_05_000001_create_hrm_foundation')");
        $db->exec('INSERT INTO tenants VALUES (1)');
        $db->exec('INSERT INTO users VALUES (1, '.($orphan ? 2 : 1).')');
    }
}
