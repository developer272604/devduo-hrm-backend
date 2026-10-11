<?php

namespace App\Domain\Attendance\Bulk;

/** Registry of bulk attendance actions. Register from a Boot class: BulkAttendanceActions::register(new MyAction()). */
final class BulkAttendanceActions
{
    /** @var array<string,BulkAttendanceAction> */
    private static array $actions = [];

    public static function register(BulkAttendanceAction $action): void
    {
        self::$actions[$action->key()] = $action;
    }

    public static function get(string $key): ?BulkAttendanceAction
    {
        return self::$actions[$key] ?? null;
    }

    /** @return array<string,BulkAttendanceAction> */
    public static function all(): array
    {
        return self::$actions;
    }
}
