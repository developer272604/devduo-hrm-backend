<?php

namespace App\Domain\Leave\Requests;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A leave request that cannot proceed. Renders as 422 (or the given status) with the explanation, conflicts and duration trace so the UI can show why. */
final class RequestRejected extends RuntimeException
{
    /** @param array<string,mixed> $payload */
    public function __construct(string $message, public readonly array $payload = [], public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => ['request' => $this->payload['errors'] ?? [$this->getMessage()]]] + $this->payload, $this->status);
    }
}
