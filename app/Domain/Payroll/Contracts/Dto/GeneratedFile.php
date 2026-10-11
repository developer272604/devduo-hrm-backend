<?php

namespace App\Domain\Payroll\Contracts\Dto;

/** A generated artefact (bank file, journal export, filing). Stored on a private disk and only served through short-lived signed downloads. */
final class GeneratedFile
{
    public readonly string $checksum;

    public function __construct(
        public readonly string $filename,
        public readonly string $mime,
        public readonly string $contents,
        public readonly int $rows,
        public readonly string $total,
    ) {
        $this->checksum = hash('sha256', $contents);
    }
}
