<?php

namespace App\Domain\People\Operations;

use App\Domain\People\Services\DuplicateWarning;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Turns any exception into a sentence safe to show users (never SQL or stack traces). */
final class Friendly
{
    public static function message(\Throwable $e, string $fallback = 'Something went wrong while processing this record.'): string
    {
        if ($e instanceof ValidationException) {
            return (string) collect($e->errors())->flatten()->first();
        }
        if ($e instanceof DuplicateWarning) {
            return 'A matching person already exists. Review the possible duplicate before continuing.';
        }
        if ($e instanceof HttpExceptionInterface && trim($e->getMessage()) !== '') {
            return $e->getMessage();
        }
        report($e);

        return $fallback;
    }
}
