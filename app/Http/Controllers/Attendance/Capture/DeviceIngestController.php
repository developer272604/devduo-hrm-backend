<?php

namespace App\Http\Controllers\Attendance\Capture;

use App\Domain\Attendance\Capture\Devices\DeviceSyncService;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\DeviceSyncRun;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Token-authenticated device API. The device and tenant come from DeviceAuthentication, never from the payload. */
class DeviceIngestController extends Controller
{
    public function events(Request $r, DeviceSyncService $sync)
    {
        $data = $r->validate(['events' => 'required|array|min:1|max:'.DeviceSyncService::MAX_BATCH, 'more' => 'sometimes|boolean']);
        $out = $sync->ingestRaw($this->device($r), $data['events'], (bool) ($data['more'] ?? false));

        return response()->json(['run_id' => $out['run']->id, 'run_status' => $out['run']->status, 'summary' => $out['summary'], 'results' => $out['results']]);
    }

    public function heartbeat(Request $r)
    {
        $device = $this->device($r);
        $device->forceFill(['last_seen_at' => now(), 'last_seen_ip' => $r->ip(), 'last_heartbeat' => array_slice($r->except(['events']), 0, 20)])->save();

        return response()->json(['ok' => true, 'server_time' => now('UTC')->toIso8601String()]);
    }

    public function syncComplete(Request $r, DeviceSyncService $sync)
    {
        $device = $this->device($r);
        $run = DeviceSyncRun::query()->where('device_id', $device->id)->where('status', 'running')->orderByDesc('id')->first();
        if ($run) {
            $run = $r->boolean('failed') ? $sync->complete($device, $run, (string) $r->input('error', 'The device reported a failure.')) : $sync->complete($device, $run);
        }

        return response()->json(['ok' => true, 'run_status' => $run?->status]);
    }

    private function device(Request $r): AttendanceDevice
    {
        return $r->attributes->get('device');
    }
}
