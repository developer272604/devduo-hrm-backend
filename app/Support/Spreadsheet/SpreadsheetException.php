<?php

namespace App\Support\Spreadsheet;

use Illuminate\Http\JsonResponse;

/**
 * A spreadsheet problem the user can act on (unsupported file, too many rows, broken header...).
 * Renders as a 422 with a plain sentence; it never carries parser internals.
 */
final class SpreadsheetException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $problem = 'invalid_file')
    {
        parent::__construct($message);
    }

    public static function tooManyRows(int $max): self
    {
        return new self('This file has more than '.number_format($max).' data rows. Split it into smaller files.', 'too_many_rows');
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self('This file is larger than '.round($maxBytes / 1048576, 1).' MB. Split it into smaller files.', 'too_large');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->problem], 422);
    }
}
