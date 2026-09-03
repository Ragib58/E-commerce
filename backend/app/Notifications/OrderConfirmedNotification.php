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
 * Sent when an order moves from Pending to Confirmed.
 *
 * Distinct from {@see OrderPlacedNotification}: placement confirms receipt,
 * this confirms *acceptance* — the store has cleared payment or agreed to ship
 * on a cash-on-delivery basis, and the order is now genuinely queued for
 * fulfilment. For cash on delivery the two often fire moments apart, which is
 * why OrderPlaced stays immutable and this one does not — a customer who finds
 * two near-identical emails noisy may reasonably want to mute the second.
 */
final class OrderConfirmedNotification extends Notification implements ShouldQueue
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
        return NotificationType::OrderConfirmed;
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

        return (new MailMessage)
            ->subject("Order confirmed — {$order->order_number}")
            ->greeting("Good news, {$order->customer_name}!")
            ->line("Your order {$order->order_number} has been confirmed and is now being prepared.")
            ->action('View your order', $this->orderUrl($order))
            ->salutation("— The {$this->companyName()} team");
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Order confirmed',
            'body' => "Order {$this->order->order_number} is confirmed and being prepared.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
        ];
    }

    private function orderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->uuid}";
    }
}
