<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Concerns\RespectsNotificationPreference;
use App\Notifications\Concerns\UsesStoreBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once, when a customer's account is created.
 *
 * Distinct from {@see CustomerVerifyEmailNotification},
 * which asks them to confirm their address — this one welcomes them once that
 * relationship exists. `NotificationType::Welcome` is marked immutable for a
 * reason specific to this notification: a preference is a setting on an
 * existing account, and there is no account to hold one until this email is
 * what establishes it exists.
 */
final class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreference;
    use UsesStoreBranding;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::Welcome;
    }

    /**
     * @return array<int, string>
     */
    protected function baseChannels(): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyName = $this->companyName();

        return (new MailMessage)
            ->subject("Welcome to {$companyName}")
            ->greeting("Welcome, {$notifiable->name}!")
            ->line("Thank you for creating an account with {$companyName}.")
            ->line('You can now track orders, save items to your wishlist, and check out faster next time.')
            ->action('Start shopping', (string) config('app.frontend_url', config('app.url')))
            ->salutation("— The {$companyName} team");
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Welcome!',
            'body' => "Thanks for creating an account with {$this->companyName()}.",
        ];
    }
}
