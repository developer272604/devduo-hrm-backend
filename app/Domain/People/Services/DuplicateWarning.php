<?php

namespace App\Domain\People\Services;

use Illuminate\Http\JsonResponse;

/** Thrown when probable duplicates exist and the caller has not confirmed. Renders as a 409 with the matches. */
final class DuplicateWarning extends \RuntimeException
{
    public function __construct(public readonly array $matches)
    {
        parent::__construct('This person may already exist.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => 'This person may already exist. Review the matches before continuing.', 'code' => 'possible_duplicate', 'duplicates' => $this->matches], 409);
    }
}
