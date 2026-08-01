<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Email verification link for a newly registered customer.
 *
 * The signed URL points at the Laravel API (which must verify the signature),
 * and that endpoint redirects the browser to the storefront afterwards. Signing
 * means the link cannot be forged or its expiry extended by editing the query
 * string.
 */
final class CustomerVerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct()
    {
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

        return (new MailMessage())
            ->subject("Verify your {$companyName} email address")
            ->greeting("Welcome to {$companyName}")
            ->line('Please confirm your email address to activate your account.')
            ->action('Verify email address', $this->verificationUrl($notifiable))
            ->line('This link expires in 60 minutes.')
            ->line('If you did not create an account, you can safely ignore this email.')
            ->salutation("— The {$companyName} team");
    }

    private function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'api.v1.auth.verify-email',
            now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                // Hashing the address rather than embedding it means the link
                // does not leak the email if it appears in a referrer header
                // or a shared screenshot.
                'hash' => sha1((string) $notifiable->getEmailForVerification()),
            ],
        );
    }

    private function companyName(): string
    {
        $name = app(SettingsService::class)->get('general.company_name');

        return is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }
}
