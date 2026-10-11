<?php

namespace App\Domain\Attendance\Capture\Devices;

use App\Domain\Attendance\Models\AttendanceDevice;

/** Registry of connector implementations, keyed by attendance_devices.provider_key. Other modules call register(). */
final class DeviceProviders
{
    /** @var array<string,AttendanceDeviceProvider> */
    private array $providers = [];

    public function register(AttendanceDeviceProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function registerBuiltIns(): void
    {
        foreach ([new GenericApiProvider, new FileImportProvider, new SimulatedProvider] as $provider) {
            $this->providers[$provider->key()] ??= $provider;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): AttendanceDeviceProvider
    {
        return $this->providers[$key] ?? throw new \InvalidArgumentException("Unknown device provider \"$key\".");
    }

    /** Unknown (legacy) keys fall back to the generic push provider. */
    public function forDevice(AttendanceDevice $device): AttendanceDeviceProvider
    {
        return $this->providers[$device->provider_key] ?? $this->providers['generic_api'];
    }

    /** @return array<string,AttendanceDeviceProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
