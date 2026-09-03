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
 * Sent when an order is marked Delivered.
 */
final class OrderDeliveredNotification extends Notification implements ShouldQueue
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
        return NotificationType::OrderDelivered;
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
            ->subject("Delivered — {$order->order_number}")
            ->greeting("Hi {$order->customer_name},")
            ->line("Your order {$order->order_number} has been delivered.")
            ->line('We hope you love it. If anything is not right, just reply to this email and we will sort it out.')
            ->action('View your order', $this->orderUrl($order))
            ->salutation("— The {$this->companyName()} team");
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Order delivered',
            'body' => "Order {$this->order->order_number} has been delivered.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
        ];
    }

    private function orderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->uuid}";
    }
}
