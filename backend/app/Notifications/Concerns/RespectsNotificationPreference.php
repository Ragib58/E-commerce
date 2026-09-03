<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\User;
use App\Services\NotificationPreferenceService;

/**
 * Filters a notification's delivery channels against the recipient's
 * preferences.
 *
 * Every concrete notification in this application declares its
 * {@see NotificationType()} and its full, unfiltered set of possible channels
 * via {@see baseChannels()}; this trait is what turns that into the *actual*
 * `via()` Laravel calls. Centralising it here means the preference check
 * cannot be forgotten on a new notification class the way it could if each one
 * re-implemented `via()` from scratch.
 *
 * `via()` returning an empty array is how Laravel's notification system
 * expresses "send nothing" — there is no separate "skip" API, so a recipient
 * who has muted every channel for a type simply receives no dispatch, silently
 * and without an exception.
 */
trait RespectsNotificationPreference
{
    /**
     * The notification type this class represents, for preference lookups.
     */
    abstract public function notificationType(): NotificationType;

    /**
     * Every channel this notification could be sent on, before preferences are
     * applied. Concrete classes list every channel they implement a `to*`
     * method for.
     *
     * @return array<int, string>
     */
    abstract protected function baseChannels(): array;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof Admin && ! $notifiable instanceof User) {
            // Defensive: this trait is only ever mixed into notifications sent
            // to one of these two account types. A third kind of notifiable
            // would mean a bug elsewhere, and every channel is offered rather
            // than guessed at.
            return $this->baseChannels();
        }

        $preferences = app(NotificationPreferenceService::class);
        $type = $this->notificationType();

        return array_values(array_filter(
            $this->baseChannels(),
            static fn (string $channel): bool => $preferences->isEnabled($notifiable, $type, $channel),
        ));
    }
}
