# Attendance capture API

Base path for session APIs: `/api/v1` (cookie session + CSRF, module `attendance`). Device API: `/api/device/v1` (bearer token, no CSRF).
Errors are JSON: `{"message": "...", "reason": "<code>"}` for business refusals (`PunchRejected`), Laravel validation shape (422) otherwise.
Native-app token auth is a later step; session cookies work for the responsive web app / PWA today.

## Employee (permission `attendance.view`)

| Method | Path | Notes |
|---|---|---|
| GET | `/attendance/me/status` | `status` IN/OUT/none, `next_direction`, `last_punch`, `worked_minutes_so_far` (display only), `expected_end`, `schedule`, `location` {mode, required, will_be_captured, purpose, privacy_note}, `methods` |
| POST | `/attendance/me/punch` | Body: `direction?` (IN/OUT; derived by toggling when omitted), `idempotency_key?` (or header `Idempotency-Key`), `client_time?` (diagnostic only), `latitude/longitude/accuracy?`, `device_identifier?`, `platform?` (ios/android => source mobile), `app_version?`, `photo?` (multipart, jpeg/png/webp, 3 MB, re-encoded to max 1024 px). Throttle 30/min. 201 new punch, 200 `duplicate:true` (double click or same key + payload). |
| GET | `/attendance/me/punches?date=Y-m-d` | Own punches for a local day. |

Rules: time is always server time; the employee is the signed-in user's own employment; coordinates are stored only when tenant `location.mode` is `policy`/`required` AND the policy has `punch_restrictions.capture_location`; there is deliberately no location ping endpoint (no continuous tracking). Same key with a different payload => 409 `idempotency_conflict`. Disabled method => 403 `method_disabled`. Locked day => 423. Outside the geofence is stored with `geofence_status: outside` unless tenant setting `location.enforcement = block` (422 `outside_geofence`).

Geofence rule: haversine distance d, accuracy a, radius R, tolerance T = max(20 m, 25% R). `d - a > R` outside; `d <= R` and `d + a <= R + T` inside; `d <= R` otherwise unavailable; else outside; accuracy > 1000 m unavailable. Policy modes: anywhere (not_required), inside, approved_location, remote_work (not_required on days covered by an approved on-duty request).

## Admin / manager

| Method | Path | Permission |
|---|---|---|
| GET | `/attendance/punches` filters: employee_id, from, to, source, device_id, direction, geofence_status, verification_status, voided (0/1/only), per_page | `attendance.manage` or `attendance.team.view` (data scope applied) |
| POST | `/attendance/punches` {employee_id, direction, punched_at, reason} manual entry | `attendance.adjust` |
| POST | `/attendance/punches/{id}/void` {reason} (never deletes) | `attendance.adjust` |
| POST | `/attendance/punches/{id}/verify` {status: pending/verified/failed, note?} | `attendance.adjust` |
| GET | `/attendance/punches/{id}/photo` (private; non-owner views audited) | adjust / location.view |

Punch objects never contain precise coordinates unless the viewer is the owner or holds `attendance.location.view` (tenant-wide, or `location.managers_see_precise`); then `location` = {latitude, longitude, accuracy_meters}. Each non-owner reveal is audited as `location_viewed`. Command `attendance:purge-location-data` (daily) nulls coordinates/photo after `location.retention_days`.

## Geofences, devices, mappings (`attendance.devices.manage`)

- `GET/POST /attendance/geofences`, `GET/PUT/DELETE /attendance/geofences/{id}` {name, location_id?, latitude, longitude, radius_meters 10-50000, active}
- `GET /attendance/device-providers` (generic_api, file_import, simulated)
- `GET/POST /attendance/devices`, `GET/PUT /attendance/devices/{id}`: {name, vendor, type biometric|rfid|face|kiosk|mobile|other, provider_key, device_identifier, location_id, timezone, status, sync_interval_minutes, config}. Config is encrypted and never returned (`has_config`).
- `POST /attendance/devices` returns `token` once. `POST .../rotate-token` issues a new token and invalidates the old one immediately; `POST .../revoke-token` disables access.
- `POST .../test`, `POST .../sync` (pull providers), `GET .../sync-runs`, `GET .../unmapped` (device users waiting for a mapping).
- Mappings: `GET/POST /attendance/devices/{id}/mappings` {employee_id, device_employee_id, effective_from, effective_to?}, `PUT/DELETE .../mappings/{mappingId}`. Overlapping ranges for the same device user are rejected (422). Adding a mapping replays waiting events.

## Device ingestion contract

Header `Authorization: Bearer atd_<prefix>.<secret>` (also `X-Device-Token`). Unknown, revoked or inactive tokens all return the same `401 {"message":"Invalid device credentials."}`. The tenant is derived from the token. Throttled.

`POST /api/device/v1/events`
```json
{"events":[{"device_employee_id":"1001","timestamp":"2026-10-07T09:02:00+05:30","direction":"IN","event_id":"abc-1"}],"more":false}
```
- At most 500 events. Accepted aliases: employee id `device_employee_id|employee_id|user_id|badge|pin`; time `timestamp|time|punched_at|occurred_at` (ISO-8601 with offset or epoch is absolute, otherwise read in the device timezone); direction `direction|type|status` (IN/OUT, anything else UNKNOWN); id `external_ref|event_id|id`.
- Response: `{run_id, run_status, summary:{inserted,duplicate,unmapped,error,received}, results:[{index,status,reason,event_id,external_ref}]}`; status is `inserted|duplicate|unmapped|error`. Resending the same `event_id` is safe (duplicate).
- Events older than 60 min are flagged `late_sync`; older than 31 days are rejected (`too_old`; use file import). Unmapped events are kept and replayed when a mapping is added.
- `more:true` keeps the sync run open; a request without it (or `POST /sync-complete`, optional `{failed:true,error:"..."}`) closes the run, triggers ONE batched recalculation (trigger `device_sync`) and emits `DeviceSyncCompleted` / `DeviceSyncFailed`.
- `POST /api/device/v1/heartbeat` any JSON (firmware, queue depth...) updates `last_seen_at`.

Rotating a token: call rotate (admin UI), copy the new token, update the device/gateway; the old token stops working at once.

## Imports (`attendance.import`)

Flow: `POST /attendance/imports` (multipart `file` CSV/XLSX <= 10 MB / 50,000 rows, `kind` punches|device_events, `device_id?`) returns preview columns and `suggested_mapping` -> `PUT /attendance/imports/{id}/mapping` {mapping:{employee_number|work_email|device_employee_id, timestamp | date+time, direction, external_ref, device, location, timezone}, options:{timezone?, date_format?}} validates -> `GET .../rows?status=` -> `POST .../commit` (202, queued job `ProcessAttendanceImport`) -> poll `GET .../{id}` (`status`, `counts`, `progress_percent`, `message`) -> `GET .../errors.csv` -> fix and `POST .../retry` (re-validates failed rows; completed rows are never re-imported). Also `GET /attendance/imports`, `GET /attendance/imports/template?kind=&format=csv|xlsx`.

Time handling: explicit offsets win; otherwise the wall time is read in row timezone > option timezone > device > location > employee zone. Non-ISO dates require `options.date_format` (PHP format, e.g. `d/m/Y H:i`) or the row errors. Wall times that repeat or do not exist (DST) produce a warning. Original values are kept in `attendance_import_rows.raw` and `attendance_events.raw_metadata.import.original`. One batched recalculation (trigger `import`) runs after commit.

Statuses: uploaded, mapped, validating, validated, queued, importing, completed, completed_with_errors, failed.
