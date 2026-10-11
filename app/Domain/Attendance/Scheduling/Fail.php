<?php

namespace App\Domain\Attendance\Scheduling;

use Illuminate\Validation\ValidationException;

/** Friendly failures: field-level 422s and coded business errors (the frontend switches on `code`). */
final class Fail
{
    /** @param array<string,string> $messages field => friendly sentence */
    public static function fields(array $messages): never
    {
        throw ValidationException::withMessages($messages);
    }

    public static function field(string $field, string $message): never
    {
        self::fields([$field => $message]);
    }

    /** Coded business error with an optional payload (conflicts, impact, ...). */
    public static function business(string $code, string $message, int $status = 422, array $extra = []): never
    {
        abort(response()->json(array_merge(['message' => $message, 'code' => $code], $extra), $status));
    }
}
