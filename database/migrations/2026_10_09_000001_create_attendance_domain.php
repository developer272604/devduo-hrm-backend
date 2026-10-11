<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Attendance, Shifts & Time Office.
 *
 * Three layers (mandatory separation):
 *   1. RAW TIME EVENTS      attendance_events            immutable punches (only correction metadata may change)
 *   2. CALCULATION          attendance_days + traces     deterministic, versioned, reproducible results
 *   3. FINALIZED ATTENDANCE attendance_days.finalization + attendance_locks   approved / locked, consumed by payroll & leave
 *
 * Time: instants are stored in UTC; the IANA zone in force is stored next to every punch, shift and device so
 * results stay explainable after a timezone change. Date-only values (attendance_date) are LOGICAL dates in the
 * shift's timezone (a 22:00→06:00 shift belongs to the date it starts on).
 */
return new class extends Migration
{
    private function table(string $name, callable $columns, bool $uuid = false, bool $timestamps = true): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns, $uuid, $timestamps) {
            $t->id();
            if ($uuid) {
                $t->uuid('uuid')->unique();
            }
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $columns($t);
            foreach ($t->getCommands() as $command) {
                if (is_string($command->index) && strlen($command->index) > 64) {
                    $command->index = substr($command->index, 0, 40).'_'.substr(sha1($command->index), 0, 16);
                }
            }
            if ($timestamps) {
                $t->timestamps();
            }
        });
    }

    /** Polymorphic assignment target shared by shift / policy / holiday / work-week assignments. */
    private function target(Blueprint $t): void
    {
        // employee | team | department | employee_group | business_unit | location | employee_type | legal_entity | country | tenant
        $t->string('target_type', 30);
        $t->string('target_id', 40)->default('*'); // numeric id, ISO country code, or '*' for tenant-wide
        $t->date('effective_from');
        $t->date('effective_to')->nullable();
        $t->smallInteger('priority')->default(0);  // tie-break inside one specificity level; higher wins
        $t->boolean('is_override')->default(false);
        $t->text('note')->nullable();
        $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
    }

    public function up(): void
    {
        // Reference entity only: payroll processing is out of scope, but locks and readiness are scoped by payroll group.
        $this->table('payroll_groups', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->unsignedTinyInteger('cutoff_day')->nullable();   // day of month attendance must be finalized
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        });

        // ---- Work weeks and holidays ---------------------------------------------------------
        $this->table('work_weeks', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->unsignedInteger('version')->default(1);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code', 'version']);
        }, true);
        $this->table('work_week_days', function (Blueprint $t) {
            $t->foreignId('work_week_id')->constrained()->restrictOnDelete();
            $t->unsignedTinyInteger('weekday');                  // ISO 1 = Monday .. 7 = Sunday
            $t->string('type', 12);                              // full | half | off | custom
            $t->unsignedSmallInteger('minutes')->nullable();     // custom/half duration; null = shift/policy default
            $t->unique(['work_week_id', 'weekday']);
        });
        $this->table('work_week_assignments', function (Blueprint $t) {
            $t->foreignId('work_week_id')->constrained()->restrictOnDelete();
            $this->target($t);
            $t->index(['tenant_id', 'target_type', 'target_id', 'effective_from'], 'work_week_assignment_target');
        });
        $this->table('holiday_calendars', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->string('country', 2)->nullable();
            $t->string('region', 80)->nullable();
            $t->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('legal_entity_id')->nullable()->constrained()->restrictOnDelete();
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        }, true);
        $this->table('holidays', function (Blueprint $t) {
            $t->foreignId('calendar_id')->constrained('holiday_calendars')->restrictOnDelete();
            $t->date('date');
            $t->string('name');
            $t->string('type', 12)->default('mandatory');       // mandatory | optional | restricted
            $t->string('portion', 12)->default('full');         // full | first_half | second_half
            $t->text('description')->nullable();
            $t->json('recurrence')->nullable();                 // {"rule":"fixed|nth_weekday","month":1,"day":26,...} used to generate next years
            $t->unique(['tenant_id', 'calendar_id', 'date']);
            $t->index(['tenant_id', 'date']);
        });
        $this->table('employee_holiday_calendar_assignments', function (Blueprint $t) {
            $t->foreignId('calendar_id')->constrained('holiday_calendars')->restrictOnDelete();
            $this->target($t);
            $t->index(['tenant_id', 'target_type', 'target_id', 'effective_from'], 'holiday_assignment_target');
        });

        // ---- Shifts, rotation, assignment -----------------------------------------------------
        $this->table('shifts', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->string('kind', 12)->default('fixed');            // fixed | flexible | split
            $t->string('timezone');                              // IANA zone the local times below are expressed in
            $t->time('start_time');
            $t->time('end_time');
            $t->boolean('crosses_midnight')->default(false);     // derived: end_time <= start_time
            $t->unsignedSmallInteger('duration_minutes');        // scheduled span incl. breaks
            $t->unsignedSmallInteger('minimum_minutes')->nullable();
            $t->unsignedSmallInteger('half_day_minutes')->nullable();
            $t->unsignedSmallInteger('full_day_minutes')->nullable();
            $t->unsignedSmallInteger('punch_window_before')->default(120); // minutes before start an IN is accepted
            $t->unsignedSmallInteger('punch_window_after')->default(240);  // minutes after end an OUT is accepted
            $t->time('flex_start_from')->nullable();
            $t->time('flex_start_to')->nullable();
            $t->time('core_start')->nullable();
            $t->time('core_end')->nullable();
            $t->unsignedSmallInteger('required_minutes')->nullable();      // flexible: duration to complete
            $t->boolean('overtime_eligible')->default(true);
            $t->json('rules')->nullable();                       // grace/late/early/break/OT/weekly-off overrides; null field = inherit policy
            $t->string('color_token', 30)->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        }, true);
        $this->table('shift_segments', function (Blueprint $t) {
            $t->foreignId('shift_id')->constrained()->restrictOnDelete();
            $t->unsignedTinyInteger('sequence');
            $t->string('kind', 12)->default('work');             // work | break
            $t->boolean('paid')->default(true);                  // for breaks
            $t->time('start_time');
            $t->time('end_time');
            $t->unsignedTinyInteger('end_day_offset')->default(0); // 1 when the segment ends after midnight relative to the shift start day
            $t->unsignedTinyInteger('start_day_offset')->default(0);
            $t->unique(['shift_id', 'sequence']);
        });
        $this->table('shift_rotations', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->unsignedSmallInteger('cycle_length');            // days in one cycle
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        }, true);
        $this->table('shift_rotation_patterns', function (Blueprint $t) {
            $t->foreignId('rotation_id')->constrained('shift_rotations')->restrictOnDelete();
            $t->unsignedSmallInteger('day_index');               // 1..cycle_length
            $t->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete(); // null = weekly off
            $t->unique(['rotation_id', 'day_index']);
        });
        $this->table('shift_assignments', function (Blueprint $t) {
            $t->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();         // fixed shift
            $t->foreignId('rotation_id')->nullable()->constrained('shift_rotations')->restrictOnDelete(); // or a rotation
            $t->date('rotation_anchor')->nullable();             // date on which day_index 1 falls
            $this->target($t);
            $t->index(['tenant_id', 'target_type', 'target_id', 'effective_from'], 'shift_assignment_target');
        }, true);

        // ---- Rosters (draft / published / changed) with full change history ------------------
        $this->table('rosters', function (Blueprint $t) {
            $t->string('name');
            $t->string('scope_type', 30)->default('custom');      // custom | department | team | location | business_unit | employee_group
            $t->string('scope_id', 40)->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 12)->default('draft');          // draft | published
            $t->unsignedInteger('publication_version')->default(0);
            $t->timestamp('published_at')->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'start_date', 'end_date']);
        }, true);
        $this->table('roster_entries', function (Blueprint $t) {
            $t->foreignId('roster_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('work_date');
            $t->string('entry_type', 10)->default('shift');      // shift | off | cleared (fall back to assignment)
            $t->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 12)->default('draft');          // draft | published | superseded
            $t->unsignedInteger('publication_version')->nullable();
            $t->foreignId('previous_entry_id')->nullable()->constrained('roster_entries')->restrictOnDelete();
            $t->string('change_reason')->nullable();
            $t->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('changed_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('superseded_at')->nullable();
            $t->index(['tenant_id', 'employee_id', 'work_date', 'status'], 'roster_entry_lookup');
            $t->index(['tenant_id', 'roster_id', 'status']);
            $t->index(['tenant_id', 'work_date', 'status']);
        });
        $this->table('shift_swap_requests', function (Blueprint $t) {
            $t->foreignId('requester_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('counterpart_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->string('type', 12);                              // swap | change | alternate
            $t->date('work_date');
            $t->date('counterpart_date')->nullable();
            $t->foreignId('from_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $t->foreignId('to_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $t->text('reason');
            $t->string('status', 20)->default('pending');        // pending | counterpart_accepted | approved | rejected | withdrawn | cancelled
            $t->json('validation')->nullable();                  // eligibility/restriction snapshot
            $t->foreignId('approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_comment')->nullable();
            $t->index(['tenant_id', 'status', 'work_date']);
        }, true);

        // ---- Policies (versioned, effective-dated) -------------------------------------------
        $this->table('attendance_policies', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->text('description')->nullable();
            $t->string('country', 2)->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        }, true);
        $this->table('attendance_policy_versions', function (Blueprint $t) {
            $t->foreignId('policy_id')->constrained('attendance_policies')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 12)->default('draft');          // draft | published | retired
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->json('rules');
            $t->string('rules_hash', 64);
            $t->text('change_note')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['policy_id', 'version']);
            $t->index(['tenant_id', 'policy_id', 'status', 'effective_from'], 'policy_version_resolution');
        });
        $this->table('attendance_policy_assignments', function (Blueprint $t) {
            $t->foreignId('policy_id')->constrained('attendance_policies')->restrictOnDelete();
            $this->target($t);
            $t->index(['tenant_id', 'target_type', 'target_id', 'effective_from'], 'policy_assignment_target');
        });

        // ---- Layer 1: raw punches -------------------------------------------------------------
        $this->table('attendance_devices', function (Blueprint $t) {
            $t->string('name');
            $t->string('vendor', 60)->nullable();
            $t->string('type', 20);                              // biometric | rfid | face | kiosk | mobile | other
            $t->string('provider_key', 40)->default('generic');  // AttendanceDeviceProvider implementation key
            $t->string('device_identifier', 120);                // serial / unique id reported by the device
            $t->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('timezone');
            $t->string('status', 12)->default('active');         // active | inactive | error
            $t->timestamp('last_sync_at')->nullable();
            $t->string('last_sync_status', 20)->nullable();
            $t->text('config_encrypted')->nullable();            // provider config / secrets (encrypted JSON)
            $t->string('auth_token_prefix', 12)->nullable();     // device API authentication: only the hash is stored
            $t->string('auth_token_hash', 64)->nullable();
            $t->unsignedInteger('sync_interval_minutes')->default(15);
            $t->unique(['tenant_id', 'device_identifier']);
            $t->index(['tenant_id', 'status']);
        }, true);
        $this->table('employee_device_mappings', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('device_id')->constrained('attendance_devices')->restrictOnDelete();
            $t->string('device_employee_id', 80);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->index(['tenant_id', 'device_id', 'device_employee_id'], 'device_mapping_lookup');
            $t->index(['tenant_id', 'employee_id']);
        });
        $this->table('device_sync_runs', function (Blueprint $t) {
            $t->foreignId('device_id')->constrained('attendance_devices')->restrictOnDelete();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->string('status', 12)->default('running');        // running | completed | failed | partial
            $t->unsignedInteger('fetched')->default(0);
            $t->unsignedInteger('inserted')->default(0);
            $t->unsignedInteger('duplicates')->default(0);
            $t->unsignedInteger('unmapped')->default(0);
            $t->unsignedInteger('errors')->default(0);
            $t->text('error_summary')->nullable();
            $t->string('cursor', 120)->nullable();
            $t->index(['tenant_id', 'device_id', 'started_at']);
        });
        $this->table('geofences', function (Blueprint $t) {
            $t->string('name');
            $t->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->unsignedInteger('radius_meters');
            $t->boolean('active')->default(true);
            $t->index(['tenant_id', 'location_id', 'active']);
        }, true);
        $this->table('attendance_imports', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind', 12)->default('punches');          // punches | device_events
            $t->foreignId('device_id')->nullable()->constrained('attendance_devices')->restrictOnDelete();
            $t->string('filename');
            $t->string('path')->nullable();
            $t->string('status', 20)->default('uploaded');
            $t->json('mapping')->nullable();
            $t->json('options')->nullable();                     // timezone, date format, employee matching key
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('warning_rows')->default(0);
            $t->unsignedInteger('error_rows')->default(0);
            $t->unsignedInteger('imported_rows')->default(0);
            $t->unsignedInteger('duplicate_rows')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->index(['tenant_id', 'status']);
        }, true);
        $this->table('attendance_import_rows', function (Blueprint $t) {
            $t->foreignId('import_id')->constrained('attendance_imports')->restrictOnDelete();
            $t->unsignedInteger('row_number');
            $t->json('raw');
            $t->json('normalized')->nullable();
            $t->string('status', 12)->default('pending');        // pending | valid | warning | error | duplicate | imported
            $t->json('errors')->nullable();
            $t->json('warnings')->nullable();
            $t->unsignedBigInteger('event_id')->nullable();
            $t->unique(['import_id', 'row_number']);
            $t->index(['tenant_id', 'import_id', 'status']);
        });
        $this->table('attendance_events', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->dateTime('punched_at');                          // UTC instant
            $t->string('direction', 8);                          // IN | OUT | UNKNOWN
            $t->string('source', 12);                            // web | mobile | biometric | import | api | admin | kiosk
            $t->foreignId('device_id')->nullable()->constrained('attendance_devices')->restrictOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('timezone');                              // IANA zone in force for the punch (device / employee / shift)
            $t->date('local_date');                              // date of the punch in `timezone` (immutable fact, used for range queries)
            $t->decimal('latitude', 10, 7)->nullable();          // sensitive; only captured per policy
            $t->decimal('longitude', 10, 7)->nullable();
            $t->unsignedInteger('accuracy_meters')->nullable();
            $t->string('geofence_status', 16)->default('not_checked'); // inside | outside | not_checked | not_required | unavailable
            $t->foreignId('geofence_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('ip_address', 45)->nullable();
            $t->string('device_identifier', 120)->nullable();    // client device fingerprint / terminal serial
            $t->string('external_ref', 120)->nullable();         // vendor event id
            $t->string('idempotency_key', 120)->nullable();
            $t->foreignId('import_id')->nullable()->constrained('attendance_imports')->restrictOnDelete();
            $t->timestamp('imported_at')->nullable();
            $t->json('raw_metadata')->nullable();                // original payload for audit/debug
            $t->string('verification_status', 16)->default('not_required'); // not_required | pending | verified | failed
            $t->string('photo_path')->nullable();                // private storage reference for photo verification
            // controlled correction metadata (the ONLY columns that may change after insert)
            $t->string('processing_state', 12)->default('pending'); // pending | processed
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('void_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete(); // admin entry
            $t->unique(['tenant_id', 'employee_id', 'idempotency_key'], 'event_idempotency');
            $t->unique(['tenant_id', 'device_id', 'external_ref'], 'event_device_external');
            $t->index(['tenant_id', 'employee_id', 'punched_at'], 'event_employee_time');
            $t->index(['tenant_id', 'local_date']);
            $t->index(['tenant_id', 'processing_state', 'punched_at'], 'event_processing_queue');
            $t->index(['tenant_id', 'device_id', 'punched_at']);
        }, true);

        $this->table('attendance_locks', function (Blueprint $t) {
            $t->string('scope_type', 20);                        // tenant | legal_entity | payroll_group | location | employee_group | employee
            $t->string('scope_id', 40)->default('*');
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 10)->default('locked');         // locked | unlocked
            $t->text('reason');
            $t->foreignId('locked_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('locked_at');
            $t->foreignId('unlock_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('unlocked_by')->nullable()->constrained('users')->restrictOnDelete();  // checker (different person)
            $t->timestamp('unlocked_at')->nullable();
            $t->text('unlock_reason')->nullable();
            $t->index(['tenant_id', 'status', 'start_date', 'end_date']);
            $t->index(['tenant_id', 'scope_type', 'scope_id']);
        }, true);

        // ---- Layer 2: calculation results and explainability ---------------------------------
        $this->table('attendance_days', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('attendance_date');                         // logical date in the shift timezone
            $t->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('policy_version_id')->nullable()->constrained('attendance_policy_versions')->restrictOnDelete();
            $t->foreignId('holiday_calendar_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('work_week_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 24);                            // standard internal codes; labels are configurable separately
            $t->dateTime('scheduled_start')->nullable();         // UTC
            $t->dateTime('scheduled_end')->nullable();
            $t->dateTime('first_in')->nullable();
            $t->dateTime('last_out')->nullable();
            $t->integer('required_minutes')->default(0);
            $t->integer('gross_minutes')->default(0);
            $t->integer('break_minutes')->default(0);
            $t->integer('net_minutes')->default(0);
            $t->integer('late_minutes')->default(0);
            $t->integer('early_exit_minutes')->default(0);
            $t->integer('overtime_minutes')->default(0);          // calculated
            $t->integer('approved_overtime_minutes')->default(0); // approved separately
            $t->string('work_mode', 10)->default('onsite');
            $t->boolean('missing_punch')->default(false);
            $t->unsignedSmallInteger('open_exceptions')->default(0);
            $t->string('confidence', 16)->default('pending');    // confirmed | review_required | pending
            $t->string('finalization', 10)->default('open');     // open | approved | locked
            $t->foreignId('lock_id')->nullable()->constrained('attendance_locks')->nullOnDelete();
            $t->unsignedInteger('calculation_version')->default(1);
            $t->string('engine_version', 20);
            $t->timestamp('calculated_at');
            $t->unique(['tenant_id', 'employee_id', 'attendance_date']);
            $t->index(['tenant_id', 'attendance_date', 'status']);
            $t->index(['tenant_id', 'attendance_date', 'confidence']);
            $t->index(['tenant_id', 'attendance_date', 'finalization']);
        }, true);
        $this->table('attendance_calculation_traces', function (Blueprint $t) {
            $t->foreignId('day_id')->constrained('attendance_days')->restrictOnDelete();
            $t->unsignedInteger('calculation_version');
            $t->string('engine_version', 20);
            $t->string('trigger', 24);                           // punch | manual | scheduled | recalculation | regularization | simulation_never
            $t->json('inputs');                                  // policy version, shift, holiday, work week, punch ids, adjustments
            $t->json('steps');                                   // ordered rule decisions
            $t->json('result');
            $t->text('explanation');
            $t->unique(['day_id', 'calculation_version']);
        });
        $this->table('attendance_exceptions', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('attendance_date');
            $t->foreignId('day_id')->nullable()->constrained('attendance_days')->restrictOnDelete();
            $t->string('type', 40);
            $t->string('severity', 16);                          // info | warning | action_required | critical
            $t->string('status', 14)->default('open');           // open | acknowledged | resolved | dismissed | auto_resolved
            $t->string('source_rule', 80);
            $t->string('fingerprint', 120);
            $t->timestamp('detected_at');
            $t->timestamp('resolved_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('resolution')->nullable();
            $t->json('details')->nullable();
            $t->unique(['tenant_id', 'employee_id', 'attendance_date', 'type', 'fingerprint'], 'exception_identity');
            $t->index(['tenant_id', 'status', 'severity']);
            $t->index(['tenant_id', 'attendance_date', 'type']);
        }, true);
        $this->table('attendance_adjustments', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('attendance_date');
            $t->string('type', 20);                              // status_override | add_punch | void_punch | time_override | mark_present...
            $t->json('before');
            $t->json('after');
            $t->text('reason');
            $t->string('status', 20)->default('applied');        // pending_approval | approved | applied | rejected | reverted
            $t->boolean('requires_checker')->default(false);
            $t->foreignId('maker_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('checker_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('checked_at')->nullable();
            $t->text('checker_comment')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->string('batch_uuid', 36)->nullable();            // bulk actions
            $t->index(['tenant_id', 'employee_id', 'attendance_date']);
            $t->index(['tenant_id', 'status']);
        }, true);

        // ---- Requests (approvals are My Work tasks; no separate approval engine) -------------
        $this->table('attendance_regularizations', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('attendance_date');
            $t->string('type', 20);                              // forgot_in | forgot_out | wrong_punch | offsite | incorrect | client_visit | official_duty
            $t->json('current_snapshot')->nullable();            // the day as calculated when requested
            $t->dateTime('requested_in')->nullable();            // UTC
            $t->dateTime('requested_out')->nullable();
            $t->text('reason');
            $t->text('comments')->nullable();
            $t->string('attachment_path')->nullable();
            $t->string('status', 14)->default('pending');        // pending | approved | rejected | withdrawn | expired
            $t->unsignedTinyInteger('current_step')->default(1);
            $t->unsignedTinyInteger('total_steps')->default(1);
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 100)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'regularization_idempotency');
            $t->index(['tenant_id', 'status', 'attendance_date']);
            $t->index(['tenant_id', 'employee_id', 'attendance_date']);
        }, true);
        $this->table('attendance_regularization_actions', function (Blueprint $t) {
            $t->foreignId('regularization_id')->constrained('attendance_regularizations')->restrictOnDelete();
            $t->unsignedTinyInteger('step')->default(1);
            $t->string('action', 14);                            // submit | approve | reject | withdraw | escalate | delegate | comment | expire
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('on_behalf_of')->nullable()->constrained('users')->restrictOnDelete(); // delegation
            $t->text('comment')->nullable();
            $t->timestamp('acted_at');
            $t->index(['tenant_id', 'regularization_id']);
        });
        $this->table('overtime_requests', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('work_date');
            $t->string('mode', 12);                              // pre_approval | post_work
            $t->string('initiated_by', 10);                      // employee | manager
            $t->string('ot_type', 16)->default('post_shift');    // pre_shift | post_shift | weekly_off | holiday | rest_day
            $t->unsignedSmallInteger('requested_minutes');
            $t->unsignedSmallInteger('calculated_minutes')->nullable();
            $t->unsignedSmallInteger('approved_minutes')->nullable();
            $t->text('reason');
            $t->string('status', 12)->default('pending');        // pending | approved | partially_approved | rejected | withdrawn | cancelled
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_comment')->nullable();
            $t->string('idempotency_key', 100)->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'overtime_idempotency');
            $t->index(['tenant_id', 'status', 'work_date']);
            $t->index(['tenant_id', 'employee_id', 'work_date']);
        }, true);
        $this->table('on_duty_requests', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('type', 16);                              // client_visit | field_work | training | official_travel | remote_work
            $t->dateTime('starts_at');                           // UTC
            $t->dateTime('ends_at');
            $t->string('timezone');
            $t->date('start_date');                              // local dates for indexed range checks
            $t->date('end_date');
            $t->boolean('full_day')->default(true);
            $t->text('reason');
            $t->string('location_text')->nullable();
            $t->string('attachment_path')->nullable();
            $t->string('status', 12)->default('pending');        // pending | approved | rejected | withdrawn | cancelled
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_comment')->nullable();
            $t->string('idempotency_key', 100)->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'on_duty_idempotency');
            $t->index(['tenant_id', 'employee_id', 'start_date', 'end_date'], 'on_duty_range');
            $t->index(['tenant_id', 'status']);
        }, true);
        $this->table('approval_delegations', function (Blueprint $t) {
            $t->foreignId('delegator_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('delegate_user_id')->constrained('users')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->json('kinds')->nullable();                       // null = all attendance approvals
            $t->string('reason')->nullable();
            $t->boolean('active')->default(true);
            $t->index(['tenant_id', 'delegator_user_id', 'starts_on', 'ends_on'], 'delegation_lookup');
        });

        // ---- Processing, locks ----------------------------------------------------------------
        $this->table('attendance_processing_runs', function (Blueprint $t) {
            $t->string('trigger', 24);                           // punch | manual | scheduled | nightly | device_sync | regularization | recalculation | import
            $t->json('scope');                                   // {employee_ids|department_id|location_id|tenant, from, to}
            $t->string('status', 12)->default('queued');         // queued | processing | completed | failed | partial
            $t->string('dedupe_key', 120)->nullable();
            $t->unsignedInteger('total_days')->default(0);
            $t->unsignedInteger('processed_days')->default(0);
            $t->unsignedInteger('failed_days')->default(0);
            $t->unsignedInteger('skipped_locked')->default(0);
            $t->json('errors')->nullable();                      // friendly, aggregated failure reasons
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->index(['tenant_id', 'status', 'created_at']);
            $t->index(['tenant_id', 'dedupe_key']);
        }, true);
        // ---- Compliance (global engine / country config / tenant policy are separate layers) --
        $this->table('compliance_rule_overrides', function (Blueprint $t) {
            $t->string('country', 2);
            $t->json('rules');                                   // max_daily_minutes, min_rest_minutes, weekly_rest, night work, OT limits, break requirements
            $t->boolean('enabled')->default(true);
            $t->unique(['tenant_id', 'country']);
        });
    }

    public function down(): void
    {
        foreach (['compliance_rule_overrides', 'attendance_locks', 'attendance_processing_runs', 'approval_delegations', 'on_duty_requests', 'overtime_requests', 'attendance_regularization_actions',
            'attendance_regularizations', 'attendance_adjustments', 'attendance_exceptions', 'attendance_calculation_traces', 'attendance_days', 'attendance_events', 'attendance_import_rows', 'attendance_imports',
            'geofences', 'device_sync_runs', 'employee_device_mappings', 'attendance_devices', 'attendance_policy_assignments', 'attendance_policy_versions', 'attendance_policies', 'shift_swap_requests',
            'roster_entries', 'rosters', 'shift_assignments', 'shift_rotation_patterns', 'shift_rotations', 'shift_segments', 'shifts', 'employee_holiday_calendar_assignments', 'holidays', 'holiday_calendars',
            'work_week_assignments', 'work_week_days', 'work_weeks', 'payroll_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
