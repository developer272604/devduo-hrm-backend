<?php

namespace App\Domain\Attendance\Capture;

use App\Domain\Attendance\Models\Geofence;
use App\Domain\People\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Geofence evaluation (spec 21-22).
 *
 * Distance: haversine great-circle distance between the reported position and the zone centre, in metres.
 *
 * Accuracy-aware rule (the reported accuracy is the radius of a circle around the position that probably contains the
 * real position). For one zone with radius R, distance d, accuracy a and tolerance T = max(20 m, 25% of R):
 *   1. d - a > R                      -> outside     (even in the best case the person is outside)
 *   2. d <= R and d + a <= R + T      -> inside      (centre inside and the accuracy circle exceeds the fence by at most T)
 *   3. d <= R (accuracy circle too big)-> unavailable (position too imprecise to confirm; the punch is stored and flagged)
 *   4. otherwise (centre outside, circle overlaps) -> outside
 * An accuracy above 1000 m is always "unavailable". Missing accuracy is treated as 0 (distance only).
 * Across several zones: any "inside" wins (nearest zone reported), then "unavailable", else "outside" (nearest zone).
 *
 * Modes (policy punch_restrictions.geofence): anywhere -> not_required | inside -> zones of the employee's location (all
 * zones when that location has none) | approved_location -> any active zone | remote_work -> like inside, but an approved
 * on-duty/remote request covering the day makes the punch not_required.
 */
class GeofenceService
{
    public const MAX_ACCURACY = 1000;

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371008.8;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    public static function tolerance(float $radius): float
    {
        return max(20.0, 0.25 * $radius);
    }

    /** @return 'inside'|'outside'|'unavailable' */
    public static function judge(float $distance, ?float $accuracy, float $radius): string
    {
        $a = max(0.0, $accuracy ?? 0.0);
        if ($a > self::MAX_ACCURACY) {
            return 'unavailable';
        }
        if ($distance - $a > $radius) {
            return 'outside';
        }
        if ($distance <= $radius) {
            return $distance + $a <= $radius + self::tolerance($radius) ? 'inside' : 'unavailable';
        }

        return 'outside';
    }

    /**
     * @param  array|string|null  $policyRule  punch_restrictions array (or its geofence mode string)
     * @return array{status:string,geofence_id:?int,geofence_name:?string,distance_meters:?int,mode:string,exempt:?string}
     */
    public function evaluate(?float $lat, ?float $lng, ?float $accuracy, Employee $employee, array|string|null $policyRule = null, ?string $date = null): array
    {
        $mode = is_array($policyRule) ? ($policyRule['geofence'] ?? 'anywhere') : ($policyRule ?: 'anywhere');
        $result = fn (string $status, ?Geofence $zone = null, ?float $distance = null, ?string $exempt = null) => ['status' => $status, 'geofence_id' => $zone?->id, 'geofence_name' => $zone?->name,
            'distance_meters' => $distance === null ? null : (int) round($distance), 'mode' => $mode, 'exempt' => $exempt];

        if ($mode === 'anywhere') {
            return $result('not_required');
        }
        $date ??= now($employee->timezone ?: 'UTC')->toDateString();
        if ($mode === 'remote_work' && $this->hasApprovedRemoteDay($employee, $date)) {
            return $result('not_required', null, null, 'on_duty');
        }
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            return $result('unavailable');
        }
        $zones = $this->zonesFor($employee, $mode, $date);
        if ($zones->isEmpty()) {
            return $result('not_checked');
        }
        $best = null;
        foreach ($zones as $zone) {
            $distance = self::distanceMeters($lat, $lng, $zone->latitude, $zone->longitude);
            $verdict = self::judge($distance, $accuracy, $zone->radius_meters);
            $rank = ['inside' => 0, 'unavailable' => 1, 'outside' => 2][$verdict];
            if ($best === null || $rank < $best['rank'] || ($rank === $best['rank'] && $distance < $best['distance'])) {
                $best = compact('verdict', 'rank', 'distance', 'zone');
            }
        }

        return $result($best['verdict'], $best['zone'], $best['distance']);
    }

    private function zonesFor(Employee $employee, string $mode, string $date)
    {
        $active = Geofence::query()->where('active', true);
        if ($mode !== 'approved_location') {
            $locationId = $employee->assignmentOn($date)?->location_id;
            if ($locationId) {
                $own = (clone $active)->where('location_id', $locationId)->get();
                if ($own->isNotEmpty()) {
                    return $own;
                }
            }
        }

        return $active->get();
    }

    public function hasApprovedRemoteDay(Employee $employee, string $date): bool
    {
        return DB::table('on_duty_requests')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->where('status', 'approved')
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists();
    }
}
