<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationAudience;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Whether an account wants a given notification, on a given channel.
 *
 * ## Opt-out, not opt-in
 *
 * The absence of a row means "on" — see the `notification_preferences`
 * migration for why that is the fail-safe direction for transactional mail.
 * This service's job is to read that absence correctly and to make writing an
 * override cheap and safe to call repeatedly.
 *
 * ## Immutable types bypass this entirely
 *
 * {@see NotificationType::isMutable()} is checked first, before any database
 * read. An order-placed confirmation or a failed-payment alert is sent
 * regardless of what — if anything — is stored, because being unable to reach
 * a customer about their own money is worse than one unwanted email. This is
 * not a default that a stored preference happens to override; it is a
 * short-circuit the storage layer never sees.
 *
 * ## Cached
 *
 * A queued notification job calls this once per recipient per send, and a
 * batch of a hundred queued "low stock" alerts to every admin should not be a
 * hundred queries against a table that changes rarely. The cache is
 * invalidated on every write, which is the only path that can make a cached
 * "on" become an "off" or vice versa.
 */
final class NotificationPreferenceService
{
    private const CACHE_TTL = 3600;

    /**
     * Whether a notification of this type should be sent to this account on
     * this channel.
     */
    public function isEnabled(Admin|User $notifiable, NotificationType $type, string $channel): bool
    {
        if (! $type->isMutable()) {
            return true;
        }

        $key = $this->cacheKey($notifiable, $type, $channel);

        return Cache::remember($key, self::CACHE_TTL, function () use ($notifiable, $type, $channel): bool {
            $preference = NotificationPreference::query()
                ->where('notifiable_type', $notifiable->getMorphClass())
                ->where('notifiable_id', $notifiable->getKey())
                ->where('type', $type->value)
                ->where('channel', $channel)
                ->first();

            /*
             * No row means no override — the type's own default channel list
             * decides. A channel this type does not offer by default (an admin
             * alert nobody has opted into email for) is off unless the account
             * explicitly turned it on, which the same table represents as an
             * `is_enabled = true` row — see enable().
             */
            if ($preference === null) {
                return in_array($channel, $type->defaultChannels(), strict: true);
            }

            return $preference->is_enabled;
        });
    }

    /**
     * Turn a notification off for an account.
     *
     * Silently refuses for an immutable type rather than throwing — a client
     * that lets someone attempt to mute "payment failed" should see the toggle
     * simply not take effect, not receive a validation error for a checkbox
     * that should not have been rendered in the first place.
     */
    public function disable(Admin|User $notifiable, NotificationType $type, string $channel = 'mail'): void
    {
        if (! $type->isMutable()) {
            return;
        }

        $this->setPreference($notifiable, $type, $channel, false);
    }

    public function enable(Admin|User $notifiable, NotificationType $type, string $channel = 'mail'): void
    {
        $this->setPreference($notifiable, $type, $channel, true);
    }

    private function setPreference(Admin|User $notifiable, NotificationType $type, string $channel, bool $enabled): void
    {
        NotificationPreference::query()->updateOrCreate(
            [
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'type' => $type->value,
                'channel' => $channel,
            ],
            ['is_enabled' => $enabled],
        );

        Cache::forget($this->cacheKey($notifiable, $type, $channel));
    }

    /**
     * Every preference for an account, across all mutable types it is
     * eligible for — the shape an account settings page renders as a list of
     * toggles.
     *
     * @return array<int, array{type: string, label: string, description: string, channel: string, is_enabled: bool}>
     */
    public function forAccount(Admin|User $notifiable): array
    {
        $audience = $notifiable instanceof Admin
            ? NotificationAudience::Admin
            : NotificationAudience::Customer;

        $rows = [];

        foreach (NotificationType::forAudience($audience) as $type) {
            if (! $type->isMutable()) {
                continue;
            }

            foreach ($type->defaultChannels() as $channel) {
                $rows[] = [
                    'type' => $type->value,
                    'label' => $type->label(),
                    'description' => $type->description(),
                    'channel' => $channel,
                    'is_enabled' => $this->isEnabled($notifiable, $type, $channel),
                ];
            }
        }

        return $rows;
    }

    private function cacheKey(Admin|User $notifiable, NotificationType $type, string $channel): string
    {
        return sprintf(
            'notification-pref:%s:%s:%s:%s',
            $notifiable->getMorphClass(),
            $notifiable->getKey(),
            $type->value,
            $channel,
        );
    }
}
