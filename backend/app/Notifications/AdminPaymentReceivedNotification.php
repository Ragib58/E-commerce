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
 * Alerts staff that a payment has been captured for an order.
 *
 * Defaults to database-only — see `NotificationType::defaultChannels()`. A
 * successful payment is routine and expected; a mail alert for every one would
 * be noise on a busy day, whereas the panel's notification bell is exactly
 * where "things that happened" belong. An admin who wants an email anyway can
 * opt in through their preferences.
 */
final class AdminPaymentReceivedNotification extends Notification implements ShouldQueue
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
        return NotificationType::AdminPaymentReceived;
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

        return (new MailMessage)
            ->subject("Payment received — {$order->order_number}")
            ->greeting('Payment received')
            ->line("**Order:** {$order->order_number}")
            ->line("**Amount:** {$this->money((int) $this->payment->amount)}")
            ->line("**Method:** {$this->payment->displayLabel()}")
            ->action('View order', $this->adminOrderUrl($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Payment received',
            'body' => "Payment of {$this->money((int) $this->payment->amount)} received for order {$this->order->order_number}.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
        ];
    }

    private function adminOrderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/admin/orders/{$order->uuid}";
    }
}
