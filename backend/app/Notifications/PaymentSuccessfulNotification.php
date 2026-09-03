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
 * Sent once a payment settles — see PaymentService::settle(), the only method
 * that may mark a payment paid. Dispatched from there, never from a controller
 * or a browser callback, for the same reason nothing in that class trusts a
 * frontend response: this email is itself a claim that money was received, and
 * it must only ever be true.
 */
final class PaymentSuccessfulNotification extends Notification implements ShouldQueue
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
        return NotificationType::PaymentSuccessful;
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
            ->subject("Payment received — {$order->order_number}")
            ->greeting("Hi {$order->customer_name},")
            ->line("We've received your payment of {$this->money((int) $this->payment->amount)} for order {$order->order_number}.")
            ->line("**Paid via:** {$this->payment->displayLabel()}")
            ->action('View your order', $this->orderUrl($order))
            ->salutation("— The {$this->companyName()} team");
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

    private function orderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->uuid}";
    }
}
