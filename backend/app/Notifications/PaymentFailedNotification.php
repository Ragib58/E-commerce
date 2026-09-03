<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\Concerns\RespectsNotificationPreference;
use App\Notifications\Concerns\UsesStoreBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a payment attempt fails.
 *
 * Immutable — the customer needs to know their card was declined so they can
 * try again, and a store that lets this be muted risks an order sitting
 * unpaid with nobody aware a retry is needed. This is the one email in the
 * catalogue where being unable to reach the customer directly costs the store
 * a sale, not just goodwill.
 */
final class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreference;
    use UsesStoreBranding;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly Order $order,
        private readonly Payment $payment,
    ) {
        $this->onQueue('notifications');
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::PaymentFailed;
    }

    /**
     * @return array<int, string>
     */
    protected function baseChannels(): array
    {
        return $this->order->user_id !== null ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;

        $message = (new MailMessage)
            ->subject("Payment could not be completed — {$order->order_number}")
            ->greeting("Hi {$order->customer_name},")
            ->line("We were unable to process your payment for order {$order->order_number}.");

        if ($this->payment->failure_reason !== null) {
            $message->line("**Reason:** {$this->payment->failure_reason}");
        }

        return $message
            ->line('Your order is still reserved — you can try paying again or choose a different method.')
            ->action('Complete your payment', $this->orderUrl($order))
            ->salutation("— The {$this->companyName()} team");
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Payment failed',
            'body' => "Payment for order {$this->order->order_number} did not go through.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
        ];
    }

    private function orderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->uuid}";
    }
}
