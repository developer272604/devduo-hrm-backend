<?php

namespace App\Domain\Attendance\Capture\Devices;

use App\Domain\Attendance\Models\AttendanceDevice;
use Carbon\CarbonImmutable;

/** Pull-model provider for tests and demos: config {events:[{device_employee_id,timestamp,direction,external_ref}], fail:bool}. */
class SimulatedProvider extends GenericApiProvider
{
    public function key(): string
    {
        return 'simulated';
    }

    public function label(): string
    {
        return 'Simulated device (testing)';
    }

    public function pulls(): bool
    {
        return true;
    }

    public function configurationSchema(): array
    {
        return [['key' => 'events', 'label' => 'Canned events (JSON)', 'type' => 'json', 'required' => false, 'secret' => false, 'help' => 'Used by automated tests.']];
    }

    public function testConnection(AttendanceDevice $device): array
    {
        return ($device->config()['fail'] ?? false) ? ['ok' => false, 'message' => 'The simulated device is configured to fail.'] : ['ok' => true, 'message' => 'Simulated device reachable.'];
    }

    public function fetchEvents(AttendanceDevice $device, ?CarbonImmutable $since): iterable
    {
        $config = $device->config();
        if ($config['fail'] ?? false) {
            throw new \RuntimeException('Simulated device connection failure.');
        }
        foreach ($config['events'] ?? [] as $raw) {
            $punch = $this->normalizePayload($raw, $device);
            if (! $since || $punch->occurredAt->gt($since)) {
                yield $punch;
            }
        }
    }
}
