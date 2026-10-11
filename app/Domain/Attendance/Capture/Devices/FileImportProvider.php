<?php

namespace App\Domain\Attendance\Capture\Devices;

use App\Domain\Attendance\Models\AttendanceDevice;

/** Devices that only export files (CSV/XLSX). Events arrive through the device-event import pipeline, never by polling. */
class FileImportProvider extends GenericApiProvider
{
    public function key(): string
    {
        return 'file_import';
    }

    public function label(): string
    {
        return 'File import (CSV / XLSX export)';
    }

    public function configurationSchema(): array
    {
        return [['key' => 'date_format', 'label' => 'Date format in the export', 'type' => 'string', 'required' => false, 'secret' => false, 'help' => 'For example d/m/Y H:i. Leave empty for ISO dates.']];
    }

    public function testConnection(AttendanceDevice $device): array
    {
        return ['ok' => true, 'message' => 'Upload the device export on the Imports page; no live connection is needed.'];
    }
}
