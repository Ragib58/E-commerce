<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset mail for customers.
 *
 * The link targets the Next.js storefront, not a Laravel route — the reset
 * form is a React page. Laravel only issues and later verifies the token.
 *
 * Queued so a slow SMTP server never delays the API response. The endpoint
 * returns the same generic message whether or not the address exists, so mail
 * latency must not become a side channel that reveals which addresses are
 * registered.
 */
final class CustomerPasswordResetNotification extends Notification implements ShouldQueue
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
        $expiryMinutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage())
            ->subject("Reset your {$companyName} password")
            ->greeting('Password reset requested')
            ->line("We received a request to reset the password for your {$companyName} account.")
            ->action('Reset password', $this->resetUrl())
            ->line("This link expires in {$expiryMinutes} minutes.")
            ->line('If you did not request a password reset, no action is required — your password has not changed.')
            ->salutation("— The {$companyName} team");
    }

    private function resetUrl(): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        // The email is included because Laravel's broker verifies the token
        // against an address; it is urlencoded so a plus-addressed mailbox
        // ("user+tag@example.com") survives the round-trip intact.
        return sprintf(
            '%s/reset-password?token=%s&email=%s',
            $base,
            $this->token,
            urlencode($this->email),
        );
    }

    /**
     * The admin-managed company name, so outbound mail is branded by the same
     * settings that brand the storefront.
     */
    private function companyName(): string
    {
        $name = app(SettingsService::class)->get('general.company_name');

        return is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }
}
