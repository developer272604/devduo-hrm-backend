<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Core People & Employee Management.
 *
 * Tenancy strategy: every tenant-owned table carries tenant_id (restrictOnDelete) and is read through
 * TenantRecord's global scope. Child tables repeat tenant_id deliberately: it makes every query
 * independently tenant-scoped (no join needed to prove ownership), lets background jobs and exports
 * filter cheaply, and makes cross-tenant leakage a one-column bug rather than a join bug.
 *
 * Identity strategy: Person (who) is separate from Employee (one employment relationship). A person
 * keeps one row across rehires; each employment period is an employees row. User is authentication only
 * and links to the person (people.user_id), never to an employment.
 *
 * Public identifiers: aggregate roots expose a UUID; sequential ids never leave the API boundary.
 *
 * This migration also retires the pre-specification compact Time/Leave slice (archived under
 * docs/legacy-time-slice). Those tables held only local demo data.
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

    public function up(): void
    {
        // Retire only an EMPTY prototype. Never assume an existing installation holds demo data.
        $legacyTables = ['time_processing_runs', 'time_period_locks', 'leave_ledger_entries', 'employee_leave_accounts', 'leave_request_segments', 'time_request_actions', 'time_requests', 'attendance_calculation_traces', 'attendance_days', 'attendance_events', 'roster_entries', 'time_assignments', 'holidays', 'leave_policy_versions', 'leave_types', 'attendance_policy_versions', 'shifts', 'holiday_calendars', 'work_weeks', 'employment_assignments', 'employees'];
        foreach ($legacyTables as $legacy) {
            if (Schema::hasTable($legacy) && \Illuminate\Support\Facades\DB::table($legacy)->exists()) {
                throw new \RuntimeException('Legacy table '.$legacy.' contains records. Preserve and migrate those records before replacing the prototype schema. No tables were removed.');
            }
        }
        foreach ($legacyTables as $legacy) {
            Schema::dropIfExists($legacy);
        }

        // ---- Roles: richer data scopes -------------------------------------------------------
        // roles.data_scope stays a string: self | direct_reports | all_subordinates | department |
        // business_unit | location | legal_entity | custom_group | tenant | explicit (legacy 'team' = direct_reports).

        $this->table('country_configurations', function (Blueprint $t) {
            $t->string('country', 2);
            $t->boolean('enabled')->default(true);
            $t->string('currency', 3)->nullable();
            $t->string('timezone')->nullable();
            $t->string('locale', 20)->nullable();
            $t->string('date_format', 30)->nullable();
            $t->string('number_format', 30)->nullable();
            $t->string('phone_code', 8)->nullable();
            $t->string('name_format', 60)->nullable();
            $t->string('working_language', 10)->nullable();
            $t->json('address_format')->nullable();      // ordered fields, labels, required flags
            $t->json('identifier_types')->nullable();    // [{key,label,pattern,classification,mask}]
            $t->json('bank_schema')->nullable();         // [{key,label,required,pattern}]
            $t->json('employment_fields')->nullable();   // country-specific employment fields
            $t->json('statutory_fields')->nullable();    // placeholders for statutory data
            $t->unique(['tenant_id', 'country']);
        });

        $this->table('lookup_values', function (Blueprint $t) {
            $t->string('kind', 40);   // employment_status | separation_reason | relationship_type | document_category | ...
            $t->string('code', 60);
            $t->string('label');
            $t->boolean('active')->default(true);
            $t->boolean('system')->default(false);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->json('metadata')->nullable();
            $t->unique(['tenant_id', 'kind', 'code']);
        });

        $this->table('people', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('legal_first_name', 120);
            $t->string('legal_middle_name', 120)->nullable();
            $t->string('legal_last_name', 120)->nullable();
            $t->string('legal_name', 360);
            $t->string('preferred_name', 120)->nullable();
            $t->string('display_name', 240);
            $t->string('gender', 30)->nullable();
            $t->string('pronouns', 40)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('marital_status', 30)->nullable();
            $t->string('nationality', 2)->nullable();
            $t->string('citizenship', 2)->nullable();
            $t->string('blood_group', 6)->nullable();
            $t->string('personal_email')->nullable();
            $t->string('personal_phone', 40)->nullable();
            $t->string('photo_path')->nullable();
            $t->string('country', 2)->nullable();
            $t->string('timezone')->nullable();
            $t->string('locale', 20)->nullable();
            $t->string('working_language', 10)->nullable();
            $t->string('preferred_language', 10)->nullable();
            $t->boolean('legal_hold')->default(false);
            $t->date('retention_until')->nullable();
            $t->timestamp('anonymized_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->softDeletes();
            $t->unique(['tenant_id', 'user_id']);
            $t->index(['tenant_id', 'display_name']);
            $t->index(['tenant_id', 'personal_email']);
            $t->index(['tenant_id', 'date_of_birth']);
        }, true);

        $this->table('employees', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('employee_number', 60);
            $t->unsignedSmallInteger('employment_sequence')->default(1);  // 1 = first employment of the person
            $t->foreignId('rehire_of_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->string('status', 40)->default('preboarding');
            $t->string('worker_type', 30)->default('employee');
            $t->date('original_joining_date');
            $t->date('joined_on');
            $t->date('employment_start_date')->nullable();
            $t->boolean('probation_applicable')->default(false);
            $t->date('probation_start')->nullable();
            $t->date('probation_end')->nullable();             // expected confirmation date
            $t->unsignedSmallInteger('probation_extensions')->default(0);
            $t->date('confirmation_date')->nullable();
            $t->date('contract_start')->nullable();
            $t->date('contract_end')->nullable();
            $t->unsignedSmallInteger('notice_period_days')->nullable();
            $t->date('retirement_date')->nullable();
            $t->date('separated_on')->nullable();              // last working date
            $t->string('work_email')->nullable();
            $t->string('work_phone', 40)->nullable();
            $t->string('country', 2);                          // employment country (drives country pack)
            $t->string('timezone');                            // employee's working timezone (IANA)
            $t->timestamp('archived_at')->nullable();
            $t->softDeletes();
            $t->unique(['tenant_id', 'employee_number']);
            $t->unique(['tenant_id', 'person_id', 'employment_sequence']);
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'joined_on']);
            $t->index(['tenant_id', 'work_email']);
            $t->index(['tenant_id', 'probation_end']);
            $t->index(['tenant_id', 'contract_end']);
        }, true);

        // Effective-dated job information (SCD type 2). Rows for one employee never overlap.
        $this->table('employee_assignments', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            foreach (['legal_entity_id' => 'legal_entities', 'business_unit_id' => 'business_units', 'department_id' => 'departments', 'team_id' => 'teams', 'cost_center_id' => 'cost_centers', 'designation_id' => 'designations', 'position_id' => 'positions', 'grade_id' => 'grades', 'level_id' => 'levels', 'employee_type_id' => 'employee_types', 'location_id' => 'locations'] as $column => $reference) {
                $t->foreignId($column)->nullable()->constrained($reference)->restrictOnDelete();
            }
            $t->string('employment_category', 60)->nullable();
            $t->string('work_mode', 20)->default('onsite');   // onsite | remote | hybrid
            $t->string('change_type', 40)->default('initial'); // initial|transfer|promotion|demotion|reorganization|correction|...
            $t->string('reason')->nullable();
            $t->string('source', 30)->default('manual');       // manual|import|workflow|system|rehire
            $t->foreignId('initiated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('comments')->nullable();
            $t->string('approval_ref', 60)->nullable();
            $t->unique(['tenant_id', 'employee_id', 'effective_from']);
            $t->index(['tenant_id', 'effective_from', 'effective_to'], 'assignment_period');
            $t->index(['tenant_id', 'department_id', 'effective_from']);
            $t->index(['tenant_id', 'location_id', 'effective_from']);
            $t->index(['tenant_id', 'legal_entity_id', 'effective_from']);
            $t->index(['tenant_id', 'business_unit_id', 'effective_from']);
            $t->index(['tenant_id', 'employee_type_id', 'effective_from']);
        });

        $this->table('employee_reporting_relationships', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('manager_id')->constrained('employees')->restrictOnDelete();
            $t->string('type', 40)->default('primary');  // primary|functional|dotted_line|hr_partner|<custom lookup code>
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('reason')->nullable();
            $t->string('source', 30)->default('manual');
            $t->foreignId('initiated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'employee_id', 'type', 'effective_from'], 'relationship_subject');
            $t->index(['tenant_id', 'manager_id', 'type', 'effective_from'], 'relationship_manager');
        });

        $this->table('employee_status_history', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('from_status', 40)->nullable();
            $t->string('to_status', 40);
            $t->string('reason_code', 60)->nullable();
            $t->text('reason')->nullable();
            $t->date('effective_date');
            $t->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'employee_id', 'effective_date']);
        });

        // Optional per-employee references to future engines, effective-dated (Organization Memory).
        $this->table('employee_policy_references', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('kind', 40); // work_schedule|attendance_policy|leave_policy|holiday_calendar|payroll_group
            $t->unsignedBigInteger('reference_id');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->index(['tenant_id', 'employee_id', 'kind', 'effective_from'], 'policy_reference_lookup');
        });

        $this->table('employee_groups', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        });
        $this->table('employee_group_members', function (Blueprint $t) {
            $t->foreignId('group_id')->constrained('employee_groups')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->unique(['group_id', 'employee_id']);
            $t->index(['tenant_id', 'employee_id']);
        });

        // Extra, explicit data-scope grants for a user beyond what their role scope derives.
        $this->table('user_data_scopes', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 30); // department|business_unit|location|legal_entity|employee_group|employee
            $t->unsignedBigInteger('reference_id');
            $t->unique(['user_id', 'kind', 'reference_id']);
            $t->index(['tenant_id', 'user_id']);
        });

        // ---- Personal sections (attached to the person: they survive rehire) ------------------
        $this->table('employee_contacts', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('kind', 10);          // email | phone
            $t->string('label', 30);         // work | personal | home | mobile | other
            $t->string('value');
            $t->string('country_code', 8)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->timestamp('verified_at')->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->index(['tenant_id', 'person_id', 'kind']);
            $t->index(['tenant_id', 'value']);
        }, true);
        $this->table('employee_addresses', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('type', 20); // current|permanent|mailing|other
            $t->string('line1');
            $t->string('line2')->nullable();
            $t->string('city', 120)->nullable();
            $t->string('region', 120)->nullable();
            $t->string('postal_code', 30)->nullable();
            $t->string('country', 2);
            $t->json('extra')->nullable(); // country-specific address parts
            $t->boolean('is_primary')->default(false);
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->index(['tenant_id', 'person_id', 'type']);
        }, true);
        $this->table('employee_emergency_contacts', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('name');
            $t->string('relationship', 60);
            $t->string('phone', 40);
            $t->string('alternate_phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->index(['tenant_id', 'person_id']);
        }, true);
        $this->table('employee_dependents', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('name');
            $t->string('relationship', 60);
            $t->date('date_of_birth')->nullable();
            $t->string('gender', 30)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->string('status', 20)->default('active'); // active|inactive
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->json('benefit_eligibility')->nullable(); // metadata only; benefits administration is out of scope
            $t->index(['tenant_id', 'person_id']);
        }, true);
        $this->table('employee_education', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('institution');
            $t->string('qualification');
            $t->string('field_of_study')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('grade', 60)->nullable();
            $t->string('country', 2)->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->string('verification_status', 20)->default('unverified');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->text('notes')->nullable();
            $t->index(['tenant_id', 'person_id']);
        }, true);
        $this->table('employee_experience', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('employer');
            $t->string('job_title');
            $t->string('location')->nullable();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->text('responsibilities')->nullable();
            $t->text('last_salary')->nullable();       // encrypted
            $t->string('last_salary_currency', 3)->nullable();
            $t->text('reason_for_leaving')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->index(['tenant_id', 'person_id']);
        }, true);

        $this->table('skills', function (Blueprint $t) {
            $t->string('name');
            $t->string('category', 80)->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'name']);
        });
        $this->table('employee_certifications', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('name');
            $t->string('issuer')->nullable();
            $t->date('issue_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('credential_id', 120)->nullable();
            $t->string('credential_url', 500)->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->string('verification_status', 20)->default('unverified');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->index(['tenant_id', 'person_id']);
            $t->index(['tenant_id', 'expiry_date']);
        }, true);
        $this->table('employee_skills', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->foreignId('skill_id')->constrained('skills')->restrictOnDelete();
            $t->string('proficiency', 20)->default('intermediate'); // beginner|intermediate|advanced|expert
            $t->decimal('years_experience', 4, 1)->nullable();
            $t->date('last_used')->nullable();
            $t->foreignId('certification_id')->nullable()->constrained('employee_certifications')->restrictOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('verification_status', 20)->default('unverified');
            $t->unique(['person_id', 'skill_id']);
            $t->index(['tenant_id', 'skill_id']);
        });

        // ---- Documents (private storage; versions are append-only) ---------------------------
        $this->table('document_types', function (Blueprint $t) {
            $t->string('code', 60);
            $t->string('name');
            $t->string('category', 40);
            $t->boolean('requires_expiry')->default(false);
            $t->boolean('mandatory')->default(false);
            $t->json('mandatory_for')->nullable(); // {countries:[], employee_types:[]}
            $t->string('classification', 30)->default('hr_confidential');
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        });
        $this->table('employee_documents', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $t->string('title');
            $t->date('issue_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('reference_number', 120)->nullable();
            $t->string('verification_status', 20)->default('pending'); // pending|verified|rejected
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->boolean('employee_visible')->default(true);
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->softDeletes();
            $t->index(['tenant_id', 'person_id']);
            $t->index(['tenant_id', 'expiry_date']);
            $t->index(['tenant_id', 'verification_status']);
        }, true);
        $this->table('employee_document_versions', function (Blueprint $t) {
            $t->foreignId('document_id')->constrained('employee_documents')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('disk', 30);
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 120);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['document_id', 'version']);
        });

        // ---- Identity, authorization and bank (highly sensitive) ------------------------------
        $this->table('identity_documents', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('type', 40);               // passport|national_id|tax_id|social_security|resident_permit|<country key>
            $t->string('country', 2);
            $t->text('number_encrypted');
            $t->string('number_hash', 64);        // HMAC blind index: duplicate detection without decrypting
            $t->string('number_last4', 8)->nullable();
            $t->date('issue_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('verification_status', 20)->default('unverified');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->index(['tenant_id', 'person_id']);
            $t->index(['tenant_id', 'number_hash']);
            $t->index(['tenant_id', 'expiry_date']);
        }, true);
        $this->table('work_authorizations', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('type', 40);               // visa|work_permit|residence_permit|...
            $t->string('country', 2);
            $t->text('number_encrypted')->nullable();
            $t->string('number_last4', 8)->nullable();
            $t->string('sponsor')->nullable();
            $t->date('issue_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('status', 20)->default('active');
            $t->string('verification_status', 20)->default('unverified');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->index(['tenant_id', 'person_id']);
            $t->index(['tenant_id', 'expiry_date']);
        }, true);
        $this->table('employee_bank_accounts', function (Blueprint $t) {
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('account_holder');
            $t->string('bank_name');
            $t->string('country', 2);
            $t->string('currency', 3);
            $t->text('details_encrypted');        // encrypted JSON of the country schema fields
            $t->string('account_last4', 8)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->string('status', 20)->default('active'); // active|retired
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->index(['tenant_id', 'person_id']);
        }, true);

        // ---- Notes, tags, custom fields -----------------------------------------------------
        $this->table('employee_notes', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->string('visibility', 20); // general_hr|manager|private_hr|employee_visible
            $t->string('category', 60)->nullable();
            $t->text('body');
            $t->json('mentions')->nullable();
            $t->json('attachments')->nullable();
            $t->softDeletes();
            $t->index(['tenant_id', 'employee_id', 'visibility']);
        }, true);
        $this->table('tags', function (Blueprint $t) {
            $t->string('name', 80);
            $t->string('color_token', 30)->default('violet');
            $t->text('description')->nullable();
            $t->string('required_permission', 60)->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'name']);
        });
        $this->table('employee_tags', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('tag_id')->constrained('tags')->restrictOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['employee_id', 'tag_id']);
            $t->index(['tenant_id', 'tag_id']);
        });
        $this->table('custom_sections', function (Blueprint $t) {
            $t->string('key', 60);
            $t->string('label');
            $t->text('description')->nullable();
            $t->json('countries')->nullable();       // null = all
            $t->json('employee_types')->nullable();
            $t->string('view_permission', 60)->nullable();
            $t->string('classification', 30)->default('hr_confidential');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'key']);
        });
        $this->table('custom_field_definitions', function (Blueprint $t) {
            $t->foreignId('section_id')->nullable()->constrained('custom_sections')->restrictOnDelete();
            $t->string('entity', 10)->default('employee'); // employee | person
            $t->string('key', 60);
            $t->string('label');
            $t->text('description')->nullable();
            $t->string('type', 30);
            $t->boolean('required')->default(false);
            $t->text('default_value')->nullable();
            $t->json('validation')->nullable();     // {min,max,pattern,...}
            $t->string('classification', 30)->default('employee');
            $t->boolean('employee_editable')->default(false);
            $t->boolean('manager_editable')->default(false);
            $t->boolean('hr_editable')->default(true);
            $t->json('countries')->nullable();
            $t->json('employee_types')->nullable();
            $t->boolean('effective_dated')->default(false);
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->unique(['tenant_id', 'entity', 'key']);
        });
        $this->table('custom_field_options', function (Blueprint $t) {
            $t->foreignId('definition_id')->constrained('custom_field_definitions')->restrictOnDelete();
            $t->string('value', 120);
            $t->string('label');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->unique(['definition_id', 'value']);
        });
        $this->table('custom_field_values', function (Blueprint $t) {
            $t->foreignId('definition_id')->constrained('custom_field_definitions')->restrictOnDelete();
            $t->string('entity_type', 10);           // employee | person
            $t->unsignedBigInteger('entity_id');
            $t->text('value')->nullable();           // JSON-encoded, validated against the definition
            $t->date('effective_from')->default('1900-01-01');
            $t->date('effective_to')->nullable();
            $t->unique(['definition_id', 'entity_type', 'entity_id', 'effective_from'], 'custom_value_identity');
            $t->index(['tenant_id', 'entity_type', 'entity_id']);
        });

        // ---- Controlled changes, lifecycle, invitations --------------------------------------
        $this->table('profile_change_requests', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('section', 40);               // personal|contact|address|emergency|dependent|bank|identity|...
            $t->string('operation', 10)->default('update');
            $t->unsignedBigInteger('target_id')->nullable();
            $t->text('old_value')->nullable();       // encrypted JSON (may contain sensitive values)
            $t->text('requested_value');             // encrypted JSON
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 20)->default('pending'); // pending|approved|rejected|cancelled
            $t->foreignId('approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_comment')->nullable();
            $t->date('effective_date')->nullable();
            $t->text('comments')->nullable();
            $t->index(['tenant_id', 'status', 'created_at']);
            $t->index(['tenant_id', 'employee_id', 'status']);
        }, true);
        $this->table('employee_invitations', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $t->string('email');
            $t->string('token_hash', 64)->unique();
            $t->string('status', 20)->default('pending'); // pending|accepted|cancelled|expired
            $t->timestamp('expires_at');
            $t->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('last_sent_at')->nullable();
            $t->unsignedSmallInteger('resend_count')->default(0);
            $t->timestamp('accepted_at')->nullable();
            $t->foreignId('accepted_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'employee_id', 'status']);
        }, true);
        $this->table('separation_records', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('type', 30);                  // resignation|termination|retirement|contract_end|absconding|other
            $t->string('reason_code', 60)->nullable();
            $t->text('reason')->nullable();
            $t->date('initiated_on');
            $t->foreignId('initiated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->date('last_working_date');
            $t->string('status', 20)->default('initiated'); // initiated|completed|withdrawn
            $t->boolean('rehire_eligible')->nullable();
            $t->text('comments')->nullable();
            $t->string('workflow_ref', 60)->nullable();
            $t->string('clearance_ref', 60)->nullable();
            $t->index(['tenant_id', 'employee_id', 'status']);
        }, true);
        $this->table('employee_number_sequences', function (Blueprint $t) {
            $t->string('scope_key', 80);
            $t->unsignedBigInteger('next_value')->default(1);
            $t->unique(['tenant_id', 'scope_key']);
        });

        // ---- Imports, exports, saved views ---------------------------------------------------
        $this->table('employee_imports', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('filename');
            $t->string('path')->nullable();
            $t->string('status', 20)->default('uploaded'); // uploaded|validated|processing|completed|partial|failed|rolled_back
            $t->json('mapping')->nullable();
            $t->json('options')->nullable();
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('warning_rows')->default(0);
            $t->unsignedInteger('error_rows')->default(0);
            $t->unsignedInteger('imported_rows')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('rolled_back_at')->nullable();
            $t->index(['tenant_id', 'status']);
        }, true);
        $this->table('employee_import_rows', function (Blueprint $t) {
            $t->foreignId('import_id')->constrained('employee_imports')->restrictOnDelete();
            $t->unsignedInteger('row_number');
            $t->json('raw');
            $t->json('normalized')->nullable();
            $t->string('status', 20)->default('pending'); // pending|valid|warning|error|imported|skipped
            $t->json('errors')->nullable();
            $t->json('warnings')->nullable();
            $t->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $t->unique(['import_id', 'row_number']);
            $t->index(['tenant_id', 'import_id', 'status']);
        });
        $this->table('employee_exports', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('format', 10);
            $t->json('filters')->nullable();
            $t->json('columns');
            $t->json('selected_ids')->nullable();
            $t->boolean('includes_sensitive')->default(false);
            $t->string('status', 20)->default('queued'); // queued|processing|completed|failed
            $t->string('path')->nullable();
            $t->unsignedInteger('row_count')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->text('error')->nullable();
            $t->index(['tenant_id', 'user_id', 'created_at']);
        }, true);
        $this->table('saved_views', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('module', 30);       // people | attendance | ...
            $t->string('name', 120);
            $t->json('filters')->nullable();
            $t->json('columns')->nullable();
            $t->json('sort')->nullable();
            $t->boolean('shared')->default(false);
            $t->index(['tenant_id', 'module', 'user_id']);
        });

        // ---- Domain events (timeline / Organization Memory feed) and data quality -------------
        $this->table('domain_events', function (Blueprint $t) {
            $t->string('type', 80);
            $t->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('person_id')->nullable()->constrained('people')->restrictOnDelete();
            $t->string('subject_type', 60)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('summary', 500);
            $t->json('payload')->nullable();
            $t->string('classification', 30)->default('employee'); // who may see this entry on the timeline
            $t->date('effective_date')->nullable();
            $t->timestamp('occurred_at')->useCurrent();
            $t->index(['tenant_id', 'employee_id', 'occurred_at'], 'event_employee_timeline');
            $t->index(['tenant_id', 'type', 'occurred_at']);
        }, true, false);
        $this->table('data_quality_findings', function (Blueprint $t) {
            $t->string('rule', 80);
            $t->string('category', 40);
            $t->string('severity', 20);  // info|warning|action_required|critical
            $t->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('subject_type', 60)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('fingerprint', 120);
            $t->string('title');
            $t->text('detail')->nullable();
            $t->string('status', 20)->default('open'); // open|acknowledged|resolved|dismissed
            $t->dateTime('detected_at');
            $t->dateTime('last_seen_at');
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution')->nullable();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('details')->nullable();
            $t->unique(['tenant_id', 'rule', 'fingerprint']);
            $t->index(['tenant_id', 'status', 'severity']);
            $t->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['data_quality_findings', 'domain_events', 'saved_views', 'employee_exports', 'employee_import_rows', 'employee_imports', 'employee_number_sequences', 'separation_records', 'employee_invitations', 'profile_change_requests', 'custom_field_values', 'custom_field_options', 'custom_field_definitions', 'custom_sections', 'employee_tags', 'tags', 'employee_notes', 'employee_bank_accounts', 'work_authorizations', 'identity_documents', 'employee_document_versions', 'employee_documents', 'document_types', 'employee_skills', 'employee_certifications', 'skills', 'employee_experience', 'employee_education', 'employee_dependents', 'employee_emergency_contacts', 'employee_addresses', 'employee_contacts', 'user_data_scopes', 'employee_group_members', 'employee_groups', 'employee_policy_references', 'employee_status_history', 'employee_reporting_relationships', 'employee_assignments', 'employees', 'people', 'lookup_values', 'country_configurations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
