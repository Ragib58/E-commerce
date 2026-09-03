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
 * Alerts staff that a new order has been placed.
 *
 * Sent to every `Admin` holding `view_orders` — see NotificationDispatcher for
 * how that list is resolved — not to a single "orders" mailbox. A store with
 * several staff members watching the queue should not depend on one person
 * forwarding emails.
 */
final class AdminNewOrderNotification extends Notification implements ShouldQueue
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
        return NotificationType::AdminNewOrder;
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
            ->subject("New order — {$order->order_number}")
            ->greeting('New order received')
            ->line("**Order:** {$order->order_number}")
            ->line("**Customer:** {$order->customer_name} ({$order->customer_email})")
            ->line("**Total:** {$this->money((int) $order->grand_total)}")
            ->line("**Payment method:** {$order->payment_method->label()}")
            ->line("**Items:** {$order->item_count}")
            ->action('View order', $this->adminOrderUrl($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New order',
            'body' => "Order {$this->order->order_number} placed by {$this->order->customer_name} — {$this->money((int) $this->order->grand_total)}.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
        ];
    }

    private function adminOrderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/admin/orders/{$order->uuid}";
    }
}
