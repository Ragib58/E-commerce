<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Order;
use App\Notifications\Concerns\RespectsNotificationPreference;
use App\Notifications\Concerns\UsesStoreBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an order is cancelled, by the customer or by staff.
 *
 * `$comment` is the reason recorded on the order's status history at the
 * moment of cancellation, passed through explicitly rather than re-read from
 * the order — the order can carry many history rows, and the notification
 * must report the reason for *this* transition, not whichever the most recent
 * one happens to be when the queued job eventually runs.
 */
final class OrderCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreference;
    use UsesStoreBranding;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly Order $order,
        private readonly ?string $comment = null,
    ) {
        $this->onQueue('notifications');
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::OrderCancelled;
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
            ->subject("Order cancelled — {$order->order_number}")
            ->greeting("Hi {$order->customer_name},")
            ->line("Your order {$order->order_number} has been cancelled.");

        if ($this->comment !== null && trim($this->comment) !== '') {
            $message->line("**Reason:** {$this->comment}");
        }

        if ($order->payment_status->isSettled()) {
            $message->line('If you were charged, a refund will be processed and you will receive a separate confirmation.');
        }

        return $message
            ->action('View your order', $this->orderUrl($order))
            ->salutation("— The {$this->companyName()} team");
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Order cancelled',
            'body' => "Order {$this->order->order_number} has been cancelled.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
        ];
    }

    private function orderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->uuid}";
    }
}
