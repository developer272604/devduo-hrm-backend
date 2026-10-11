<?php

namespace App\Domain\People\Insights;

use Illuminate\Support\Str;

/**
 * Catalogue of domain event types for the timeline UI: type => category / icon key / label.
 * Modules (Attendance, Leave, ...) add their own types with register(); unknown types still render with a
 * humanised label under the "other" group, so a new event never breaks the timeline.
 */
final class DomainEventTypes
{
    public const CATEGORIES = [
        'employment' => 'Employment', 'job' => 'Job & organization', 'reporting' => 'Reporting line', 'profile' => 'Profile',
        'documents' => 'Documents', 'requests' => 'Change requests', 'other' => 'Other',
    ];

    /** type => [category, icon key, label] */
    private const CORE = [
        'EmployeeCreated' => ['employment', 'user-plus', 'Joined company'],
        'EmployeeActivated' => ['employment', 'user-check', 'Activated'],
        'EmployeeUpdated' => ['employment', 'edit', 'Employment details updated'],
        'EmploymentStatusChanged' => ['employment', 'refresh', 'Employment status changed'],
        'EmployeeSeparated' => ['employment', 'user-minus', 'Separated'],
        'EmployeeRehired' => ['employment', 'user-plus', 'Rehired'],
        'ProbationDue' => ['employment', 'clock', 'Probation review due'],
        'EmployeeTransferred' => ['job', 'move', 'Transferred'],
        'EmployeePromoted' => ['job', 'trending-up', 'Promoted'],
        'ManagerChanged' => ['reporting', 'users', 'Reporting manager changed'],
        'PersonalInformationUpdated' => ['profile', 'user', 'Personal information updated'],
        'DocumentUploaded' => ['documents', 'file-plus', 'Document added'],
        'DocumentVerified' => ['documents', 'file-check', 'Document verified'],
        'ProfileChangeRequested' => ['requests', 'send', 'Profile change requested'],
        'ProfileChangeApproved' => ['requests', 'check', 'Profile change approved'],
        'ProfileChangeRejected' => ['requests', 'x', 'Profile change rejected'],
    ];

    /** Section services record "<Stem><Verb>" events (ContactAdded, SkillUpdated, ...). */
    private const STEMS = [
        'Contact' => ['profile', 'Contact detail'], 'Address' => ['profile', 'Address'], 'EmergencyContact' => ['profile', 'Emergency contact'],
        'Dependent' => ['profile', 'Dependent'], 'Education' => ['profile', 'Education'], 'Experience' => ['profile', 'Experience'], 'Skill' => ['profile', 'Skill'],
        'Certification' => ['profile', 'Certification'], 'Identity' => ['documents', 'Identity document'], 'WorkAuthorization' => ['documents', 'Work authorization'],
        'Document' => ['documents', 'Document'], 'BankAccount' => ['profile', 'Bank account'], 'Note' => ['profile', 'Note'], 'Tag' => ['profile', 'Tag'],
    ];

    /** @var array<string,array{0:string,1:string,2:string}> */
    private static array $extra = [];

    /** Registers (or overrides) a type. Category may be a core group or a new one (it is then listed with the same label). */
    public static function register(string $type, string $category, string $icon, string $label): void
    {
        self::$extra[$type] = [$category, $icon, $label];
    }

    /** @return array{type:string,category:string,icon:string,label:string} */
    public static function describe(string $type): array
    {
        if ($found = self::$extra[$type] ?? self::CORE[$type] ?? null) {
            return ['type' => $type, 'category' => $found[0], 'icon' => $found[1], 'label' => $found[2]];
        }
        if (preg_match('/^(.+?)(Added|Updated|Removed|Deleted|Verified|Uploaded|Created)$/', $type, $m) && isset(self::STEMS[$m[1]])) {
            [$category, $noun] = self::STEMS[$m[1]];
            $verb = $m[2] === 'Uploaded' ? 'added' : strtolower($m[2]);

            return ['type' => $type, 'category' => $category, 'icon' => $m[2] === 'Verified' ? 'file-check' : 'edit', 'label' => $noun.' '.$verb];
        }

        return ['type' => $type, 'category' => 'other', 'icon' => 'activity', 'label' => Str::headline($type)];
    }

    /** @return array<string,array{type:string,category:string,icon:string,label:string}> */
    public static function all(): array
    {
        $out = [];
        foreach ([...array_keys(self::CORE), ...array_keys(self::$extra)] as $type) {
            $out[$type] = self::describe($type);
        }

        return $out;
    }

    /** @return array<string,string> category key => label (core groups plus any registered by modules). */
    public static function categories(): array
    {
        $out = self::CATEGORIES;
        foreach (self::$extra as [$category]) {
            $out[$category] ??= Str::headline($category);
        }

        return $out;
    }

    /** Event types known to belong to a category (stem-based types are matched by the timeline with LIKE patterns). */
    public static function typesIn(string $category): array
    {
        return array_keys(array_filter(self::all(), fn ($d) => $d['category'] === $category));
    }

    /** @return array<int,string> LIKE patterns for stem-based types of a category (e.g. "Contact%"). */
    public static function stemPatterns(string $category): array
    {
        $out = [];
        foreach (self::STEMS as $stem => [$cat]) {
            if ($cat === $category) {
                $out[] = $stem.'%';
            }
        }

        return $out;
    }

    /** Test helper: forget module registrations. */
    public static function reset(): void
    {
        self::$extra = [];
    }
}
