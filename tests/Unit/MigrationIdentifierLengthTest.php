<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests run on SQLite, which accepts any identifier length, but production runs MySQL/MariaDB, which refuses index and constraint names over
 * 64 characters. This static check computes the name Laravel would generate for every unnamed index() and unique() call in the migrations and
 * fails when one is too long, so the mistake is caught here and not half-way through a production migration.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    private const LIMIT = 64;

    public function test_generated_index_names_fit_in_mysql(): void
    {
        $tooLong = [];
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
            $src = file_get_contents($file);
            if (str_contains($src, 'strlen($command->index) > 64')) {
                continue; // this file shortens long generated names itself before creating the table
            }
            // Walk the file in order so each index is attributed to the table most recently opened.
            preg_match_all('/(?:Schema::(?:create|table)\(|\$this->table\()\s*\'([a-z0-9_]+)\'|->(index|unique)\(\s*\[([^\]]+)\]\s*(?:,\s*\'([^\']+)\')?\s*\)/', $src, $m, PREG_SET_ORDER);
            $table = null;
            foreach ($m as $hit) {
                if (! empty($hit[1])) {
                    $table = $hit[1];

                    continue;
                }
                if ($table === null || ! empty($hit[4])) {
                    continue; // an explicit name was given
                }
                $columns = array_map(fn ($c) => trim($c, " '\""), explode(',', $hit[3]));
                $name = strtolower($table.'_'.implode('_', $columns).'_'.$hit[2]);
                if (strlen($name) > self::LIMIT) {
                    $tooLong[] = basename($file).': '.$name.' ('.strlen($name).')';
                }
            }
        }
        $this->assertSame([], $tooLong, "Index names longer than 64 characters need an explicit short name:\n".implode("\n", $tooLong));
    }
}
