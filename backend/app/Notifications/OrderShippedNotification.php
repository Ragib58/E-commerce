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
 * Sent when an order moves to Shipped.
 *
 * Tracking is read from the order at send time, not passed in separately —
 * `OrderService::setTracking()` records the courier and tracking number before
 * the status transition fires this notification (see its docblock: "so a
 * customer notified of the move to Shipped can already see the number"), so by
 * the time this class runs the order already carries everything it needs.
 */
final class OrderShippedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreference;
    use UsesStoreBranding;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly Order $order,
    ) {
        $this->onQueue('notifications');
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::OrderShipped;
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
            ->subject("Your order is on its way — {$order->order_number}")
            ->greeting("Great news, {$order->customer_name}!")
            ->line("Your order {$order->order_number} has shipped.");

        if ($order->courier_name !== null) {
            $message->line("**Courier:** {$order->courier_name}");
        }

        if ($order->tracking_number !== null) {
            $message->line("**Tracking number:** {$order->tracking_number}");
        }

        if ($order->tracking_url !== null) {
            $message->action('Track your parcel', $order->tracking_url);
        } else {
            $message->action('View your order', $this->orderUrl($order));
        }

        return $message->salutation("— The {$this->companyName()} team");
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Order shipped',
            'body' => "Order {$this->order->order_number} is on its way.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
            'tracking_number' => $this->order->tracking_number,
            'tracking_url' => $this->order->tracking_url,
        ];
    }

    private function orderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->uuid}";
    }
}
