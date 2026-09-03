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
 * Alerts staff that a payment attempt failed.
 *
 * Immutable, matching the customer-facing counterpart's reasoning turned
 * around: a run of failed payments is often the first sign of a
 * misconfigured gateway or a fraud attempt, and a store that has muted this
 * alert finds out only when a customer complains — or not at all.
 */
final class AdminFailedPaymentNotification extends Notification implements ShouldQueue
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
        return NotificationType::AdminFailedPayment;
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
        $order = $this->order;

        $message = (new MailMessage)
            ->subject("Payment failed — {$order->order_number}")
            ->greeting('Payment failed')
            ->line("**Order:** {$order->order_number}")
            ->line("**Customer:** {$order->customer_name} ({$order->customer_email})")
            ->line('**Gateway:** '.($this->payment->gateway ?? 'unknown'));

        if ($this->payment->failure_reason !== null) {
            $message->line("**Reason:** {$this->payment->failure_reason}");
        }

        return $message->action('View order', $this->adminOrderUrl($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Payment failed',
            'body' => "Payment failed for order {$this->order->order_number}.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
        ];
    }

    private function adminOrderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/admin/orders/{$order->uuid}";
    }
}
