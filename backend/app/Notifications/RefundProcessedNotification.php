<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Order;
use App\Models\Refund;
use App\Notifications\Concerns\RespectsNotificationPreference;
use App\Notifications\Concerns\UsesStoreBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a refund is issued — see RefundService, which reverses money at
 * the gateway (or records an offline reversal) before this fires. The amount
 * quoted is always {@see Refund::$amount}, the figure actually returned, not
 * the order's original total: a partial refund must read as partial.
 */
final class RefundProcessedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreference;
    use UsesStoreBranding;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly Order $order,
        private readonly Refund $refund,
    ) {
        $this->onQueue('notifications');
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::RefundProcessed;
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
        $isFullRefund = ! $order->isRefundable();

        $message = (new MailMessage)
            ->subject("Refund processed — {$order->order_number}")
            ->greeting("Hi {$order->customer_name},")
            ->line("We've processed a refund of {$this->money((int) $this->refund->amount)} for order {$order->order_number}.");

        if (! $isFullRefund) {
            $message->line(
                "This is a partial refund. {$this->money($order->refundable_amount)} remains on this order.",
            );
        }

        if ($this->refund->reason !== null) {
            $message->line("**Reason:** {$this->refund->reason}");
        }

        return $message
            ->line('Please allow 5–10 business days for the refund to appear on your original payment method.')
            ->action('View your order', $this->orderUrl($order))
            ->salutation("— The {$this->companyName()} team");
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Refund processed',
            'body' => "Refund of {$this->money((int) $this->refund->amount)} processed for order {$this->order->order_number}.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
        ];
    }

    private function orderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->uuid}";
    }
}
