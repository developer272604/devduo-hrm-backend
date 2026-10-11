<?php

namespace App\Domain\Payroll\Results;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A result the payroll store refuses. `reason` is a stable machine code; the message is safe to show to the user. */
final class ResultRejected extends HttpException
{
    public function __construct(public readonly string $reason, string $message, int $status = 422)
    {
        parent::__construct($status, $message);
    }

    public static function locked(string $status): self
    {
        return new self('run_locked', "This payroll run is $status. Ordinary recalculation is not allowed; reopen it with approval or post a controlled correction.", 423);
    }

    public static function invalid(string $message): self
    {
        return new self('invalid_result', $message);
    }
}
