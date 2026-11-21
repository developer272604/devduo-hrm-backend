<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use RuntimeException;

final class VerifySqliteBackup extends Command
{
    protected $signature = 'backup:verify-sqlite {snapshot : Path to a standalone SQLite backup}';

    protected $description = 'Verify a SQLite backup against an isolated temporary copy';

    public function handle(): int
    {
        $snapshot = realpath((string) $this->argument('snapshot'));
        if ($snapshot === false || ! is_file($snapshot) || ! is_readable($snapshot)) {
            $this->error('Provide a readable SQLite backup file.');

            return self::FAILURE;
        }

        $copy = tempnam(sys_get_temp_dir(), 'hrm-restore-');
        if ($copy === false) {
            $this->error('Unable to create a temporary restore file.');

            return self::FAILURE;
        }
        $database = null;

        try {
            if ($this->hasSidecars($snapshot)) {
                throw new RuntimeException('Use a standalone backup without SQLite journal, WAL or SHM files.');
            }
            $digest = hash_file('sha256', $snapshot);
            if ($digest === false || ! @copy($snapshot, $copy)
                || hash_file('sha256', $copy) !== $digest || hash_file('sha256', $snapshot) !== $digest
                || $this->hasSidecars($snapshot)) {
                throw new RuntimeException('The source changed or could not be copied.');
            }

            $database = new PDO('sqlite:'.$copy, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $database->exec('PRAGMA query_only = ON');
            $database->exec('PRAGMA trusted_schema = OFF');
            if ($database->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
                throw new RuntimeException('SQLite integrity checks failed.');
            }
            if ($database->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_NUM) !== false) {
                throw new RuntimeException('SQLite foreign-key checks failed.');
            }

            $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
            if (array_diff(['migrations', 'tenants', 'users'], $tables)
                || ! $database->query("SELECT 1 FROM migrations WHERE migration = '2026_10_05_000001_create_hrm_foundation' LIMIT 1")->fetchColumn()) {
                throw new RuntimeException('The backup does not contain the expected HRM schema and migration history.');
            }

            $this->line(json_encode([
                'status' => 'verified',
                'engine' => 'sqlite',
                'sha256' => $digest,
                'tables' => count($tables),
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\PDOException) {
            $this->error('The temporary copy could not be verified as a SQLite database.');

            return self::FAILURE;
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $database = null;
            unlink($copy);
        }
    }

    private function hasSidecars(string $snapshot): bool
    {
        foreach (['-journal', '-wal', '-shm'] as $suffix) {
            clearstatcache(true, $snapshot.$suffix);
            if (file_exists($snapshot.$suffix)) {
                return true;
            }
        }

        return false;
    }
}
