<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\ConfigurationUrlParser;
use PDO;
use PDOException;
use RuntimeException;

final class VerifyMysqlRestore extends Command
{
    protected $signature = 'backup:verify-mysql
        {--expect-tenants= : Expected tenant count from the backup inventory}
        {--expect-users= : Expected user count from the backup inventory}';

    protected $description = 'Verify an isolated MySQL or MariaDB restore using a read-only transaction';

    public function handle(): int
    {
        try {
            $restore = config('database.restore_verification');
            $schema = $this->schema($restore);
            $expected = [
                'tenants' => $this->expectedCount('expect-tenants'),
                'users' => $this->expectedCount('expect-users'),
            ];

            $database = new PDO(
                'mysql:host='.$restore['host'].';port='.$restore['port'].';dbname='.$schema.';charset=utf8mb4',
                $restore['username'],
                $restore['password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
            $database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $database->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

            try {
                $stats = $this->verify($database, $schema, $expected);
            } finally {
                $database->exec('ROLLBACK');
            }

            $this->line(json_encode(['status' => 'verified', 'engine' => 'mysql-compatible'] + $stats, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (PDOException) {
            $this->error('The isolated database connection or a verification query failed.');

            return self::FAILURE;
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function schema(array $restore): string
    {
        $schema = (string) ($restore['database'] ?? '');
        $primary = (new ConfigurationUrlParser)->parseConfiguration(config('database.connections.'.config('database.default'), []));
        if (! preg_match('/^hrm_restore_[a-z0-9_]{1,52}$/D', $schema) || strcasecmp((string) ($primary['database'] ?? ''), $schema) === 0) {
            throw new RuntimeException('Configure a separate restore schema named hrm_restore_<identifier>.');
        }

        $host = (string) ($restore['host'] ?? '');
        if (! preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || ! filter_var($restore['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])
            || empty($restore['username'])) {
            throw new RuntimeException('Configure an explicit restore host, port and read-only database account.');
        }

        return $schema;
    }

    private function expectedCount(string $option): ?int
    {
        $value = $this->option($option);
        if ($value === null) {
            return null;
        }
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($count === false) {
            throw new RuntimeException('Expected counts must be nonnegative integers.');
        }

        return $count;
    }

    private function verify(PDO $database, string $schema, array $expected): array
    {
        if ($database->query('SELECT DATABASE()')->fetchColumn() !== $schema) {
            throw new RuntimeException('The server selected an unexpected database.');
        }
        $tables = $database->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff(['migrations', 'tenants', 'users', 'roles', 'role_user'], $tables)
            || ! $database->query("SELECT 1 FROM migrations WHERE migration = '2026_10_05_000001_create_hrm_foundation' LIMIT 1")->fetchColumn()) {
            throw new RuntimeException('The restore is missing the core HRM schema or migration history.');
        }

        $orphan = $database->query('SELECT 1 FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE u.tenant_id IS NOT NULL AND t.id IS NULL LIMIT 1')->fetchColumn();
        $badRole = $database->query('SELECT 1 FROM role_user ru LEFT JOIN users u ON u.id = ru.user_id LEFT JOIN roles r ON r.id = ru.role_id
            WHERE u.id IS NULL OR r.id IS NULL OR u.tenant_id IS NULL OR u.tenant_id <> r.tenant_id LIMIT 1')->fetchColumn();
        if ($orphan || $badRole) {
            throw new RuntimeException('The restore contains invalid user or tenant role references.');
        }

        $stats = ['tables' => count($tables)];
        foreach (['tenants', 'users'] as $table) {
            $stats[$table] = (int) $database->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
            if ($expected[$table] !== null && $expected[$table] !== $stats[$table]) {
                throw new RuntimeException('The restore does not match the expected '.$table.' count.');
            }
        }

        return $stats;
    }
}
