<?php

namespace App\Domain\Access;

use App\Domain\People\Models\Employee;
use App\Models\User;

/**
 * Field-level authorization. Decisions combine the field's data classification, the viewer's relationship to
 * the subject (self / line manager / HR with permission) and tenant policy. Everything runs server-side;
 * the frontend only renders what the API returns.
 *
 * The caller must already have established that the viewer may access the subject at all (DataScope).
 */
final class FieldAccess
{
    public const FULL = 'full';

    public const MASKED = 'masked';

    public const HIDDEN = 'hidden';

    /** Permission that lets HR edit a field group directly. */
    private const EDIT_PERMISSION = [
        'personal' => 'people.manage', 'contact' => 'people.manage', 'address' => 'people.manage', 'emergency' => 'people.manage', 'dependent' => 'people.manage',
        'education' => 'people.manage', 'experience' => 'people.manage', 'skill' => 'people.manage', 'certification' => 'people.manage', 'employment' => 'people.manage',
        'job' => 'people.manage', 'document' => 'people.documents.manage', 'identity' => 'people.identity.manage', 'bank' => 'people.bank.manage', 'note' => 'people.notes.manage',
    ];

    public function __construct(private DataScope $scope) {}

    /** @return array{self:bool,manager:bool} */
    public function relation(User $viewer, ?Employee $subject): array
    {
        if (! $subject) {
            return ['self' => false, 'manager' => false];
        }

        return ['self' => $this->scope->isSelf($viewer, $subject), 'manager' => $this->scope->isManagerOf($viewer, $subject)];
    }

    /** @param array{self:bool,manager:bool}|null $relation precomputed relation() to avoid repeated lookups per field */
    public function view(User $viewer, string $field, ?Employee $subject, ?array $relation = null): string
    {
        return $this->viewClass($viewer, PeopleSettings::classification($field), $subject, $relation, FieldRegistry::privacyKey($field));
    }

    /**
     * Visibility of data of a given classification for a subject (used for fields, timeline entries, exports).
     * $privacyKey applies tenant directory privacy to public_internal data for coworkers.
     */
    public function viewClass(User $viewer, string $class, ?Employee $subject, ?array $relation = null, ?string $privacyKey = null): string
    {
        if ($class === Classification::SYSTEM_RESTRICTED) {
            return self::HIDDEN;
        }
        ['self' => $self, 'manager' => $manager] = $relation ?? $this->relation($viewer, $subject);
        $hr = $viewer->allows('people.personal.view');

        return match ($class) {
            Classification::PUBLIC_INTERNAL => ($self || $hr || $privacyKey === null || PeopleSettings::directoryVisible($privacyKey)) ? self::FULL : self::HIDDEN,
            Classification::EMPLOYEE => ($self || $hr) ? self::FULL : self::HIDDEN,
            Classification::MANAGER_SENSITIVE => ($manager || $viewer->allows('people.confidential.view')) ? self::FULL : self::HIDDEN,
            Classification::HR_CONFIDENTIAL => $viewer->allows('people.confidential.view') ? self::FULL : self::HIDDEN,
            Classification::HIGHLY_SENSITIVE => $viewer->allows('people.sensitive.reveal') ? self::FULL : (($self || $viewer->allows('people.sensitive.view')) ? self::MASKED : self::HIDDEN),
            default => self::HIDDEN,
        };
    }

    public function canView(User $viewer, string $field, ?Employee $subject, ?array $relation = null): bool
    {
        return $this->view($viewer, $field, $subject, $relation) !== self::HIDDEN;
    }

    /** direct | request | none — what the viewer may do to this field on the subject. */
    public function edit(User $viewer, string $field, ?Employee $subject, ?array $relation = null): string
    {
        $relation ??= $this->relation($viewer, $subject);
        if (! FieldRegistry::known($field) || $this->view($viewer, $field, $subject, $relation) === self::HIDDEN) {
            return 'none';
        }
        $group = FieldRegistry::group($field);

        return $this->editGroup($viewer, $group, $subject, $relation);
    }

    /** direct | request | none for a whole field group (personal, contact, bank, ...). */
    public function editGroup(User $viewer, string $group, ?Employee $subject, ?array $relation = null): string
    {
        $relation ??= $this->relation($viewer, $subject);
        $permission = self::EDIT_PERMISSION[$group] ?? 'people.manage';
        if ($viewer->allows($permission) && $subject && $this->scope->canAccess($viewer, $subject, 'people.manage')) {
            return 'direct';
        }

        return $relation['self'] ? PeopleSettings::editRule($group) : 'none';
    }

    /**
     * Applies view rules to a map of "<entity>.<field>" => value, masking or removing values.
     *
     * @param  array<string,mixed>  $values
     */
    public function filter(User $viewer, ?Employee $subject, array $values): array
    {
        $out = [];
        foreach ($values as $field => $value) {
            $mode = FieldRegistry::known($field) ? $this->view($viewer, $field, $subject) : self::HIDDEN;
            if ($mode === self::FULL) {
                $out[$field] = $value;
            } elseif ($mode === self::MASKED) {
                $out[$field] = is_string($value) ? self::mask($value) : null;
            }
        }

        return $out;
    }

    public static function mask(?string $value, int $keep = 4): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $length = mb_strlen($value);

        return $length <= $keep ? str_repeat('•', $length) : str_repeat('•', min(8, $length - $keep)).mb_substr($value, -$keep);
    }

    /** Whether the viewer may include a classified field in an export. */
    public function exportable(User $viewer, string $field, ?Employee $subject = null): bool
    {
        $class = PeopleSettings::classification($field);
        if ($class === Classification::SYSTEM_RESTRICTED) {
            return false;
        }
        if (in_array($class, Classification::EXPORT_SENSITIVE, true) && ! $viewer->allows('people.export_sensitive')) {
            return false;
        }

        return $this->view($viewer, $field, $subject) === self::FULL;
    }
}
