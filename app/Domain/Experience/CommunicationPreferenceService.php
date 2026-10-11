<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Experience\Models\ExperienceNotificationPreference;
use App\Models\User;

final class CommunicationPreferenceService
{
    public const CATEGORIES = ['announcements', 'surveys', 'recognition', 'events'];

    public function present(User $user): array
    {
        $preference = ExperienceNotificationPreference::firstOrCreate(['user_id' => $user->id], $this->defaults());
        $channels = [];
        foreach (self::CATEGORIES as $category) {
            $channels[$category] = ['in_app' => (bool) $preference->{$category.'_in_app'}, 'email' => (bool) $preference->{$category.'_email'}];
        }
        return ['channels' => $channels];
    }

    public function update(User $user, array $channels): array
    {
        $preference = ExperienceNotificationPreference::firstOrCreate(['user_id' => $user->id], $this->defaults());
        foreach (self::CATEGORIES as $category) {
            foreach (['in_app', 'email'] as $channel) {
                if (isset($channels[$category]) && array_key_exists($channel, $channels[$category])) {
                    $preference->{$category.'_'.$channel} = $channels[$category][$channel];
                }
            }
        }
        $preference->save();
        Audit::record('experience.communication_preferences_updated', 'experience', 'user:'.$user->id);
        return $this->present($user);
    }

    public function allowsInApp(int $userId, string $category): bool
    {
        $field = match ($category) {
            'experience.announcement' => 'announcements_in_app',
            'experience.survey' => 'surveys_in_app',
            'experience.recognition' => 'recognition_in_app',
            'experience.event' => 'events_in_app',
            default => null,
        };
        if (! $field) return true;
        $value = ExperienceNotificationPreference::where('user_id', $userId)->value($field);
        return $value === null || (bool) $value;
    }

    private function defaults(): array
    {
        $defaults = [];
        foreach (self::CATEGORIES as $category) {
            $defaults[$category.'_in_app'] = true;
            $defaults[$category.'_email'] = false;
        }
        return $defaults;
    }
}
