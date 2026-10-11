<?php

namespace App\Domain\Attendance\Capture\Devices;

use App\Domain\Attendance\Models\AttendanceDevice;
use Carbon\CarbonImmutable;

/**
 * A vendor connector. Providers only translate vendor data into NormalizedPunch objects; they never write punches
 * and never contain attendance rules.
 */
interface AttendanceDeviceProvider
{
    public function key(): string;

    public function label(): string;

    /** True for providers the platform polls (fetchEvents); false for push models (the device/gateway calls our API). */
    public function pulls(): bool;

    /** Field definitions for the admin UI: [{key,label,type,required,secret,help}]. Secret fields are stored encrypted and never returned. */
    public function configurationSchema(): array;

    /** @return array{ok:bool,message:string} */
    public function testConnection(AttendanceDevice $device): array;

    /** @return iterable<NormalizedPunch> pull-model providers return events since the given time; push providers return []. */
    public function fetchEvents(AttendanceDevice $device, ?CarbonImmutable $since): iterable;

    /** Normalizes one raw vendor event (as posted to the ingestion API); throws \InvalidArgumentException with a friendly message when invalid. */
    public function normalizePayload(array $raw, ?AttendanceDevice $device = null): NormalizedPunch;
}
