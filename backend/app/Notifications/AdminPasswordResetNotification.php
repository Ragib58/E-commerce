<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset mail for staff.
 *
 * Points at the admin panel, not the storefront, and carries a security notice
 * appropriate to a privileged account.
 */
final class AdminPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $token,
        private readonly string $email,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyName = $this->companyName();
        $expiryMinutes = (int) config('auth.passwords.admins.expire', 30);

        return (new MailMessage())
            ->subject("Reset your {$companyName} administrator password")
            ->greeting('Administrator password reset')
            ->line("A password reset was requested for your {$companyName} administrator account.")
            ->action('Reset password', $this->resetUrl())
            ->line("This link expires in {$expiryMinutes} minutes.")
            // Unlike the customer notification, this one asks for escalation:
            // an unrequested reset on a privileged account may indicate an
            // attempt to take it over, and someone should look at it.
            ->line('If you did not request this, contact your system administrator immediately — it may indicate an attempt to access your account.')
            ->salutation("— {$companyName}");
    }

    private function resetUrl(): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return sprintf(
            '%s/admin/reset-password?token=%s&email=%s',
            $base,
            $this->token,
            urlencode($this->email),
        );
    }

    private function companyName(): string
    {
        $name = app(SettingsService::class)->get('general.company_name');

        return is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }
}
