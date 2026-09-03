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
 * Order confirmation — the receipt a shopper expects the moment they place an
 * order.
 *
 * ## Immutable, and why
 *
 * `NotificationType::OrderPlaced` is one of the four types
 * {@see NotificationType::isMutable()} exempts from preferences. A
 * customer who has just spent money is entitled to a receipt regardless of
 * whatever mail preference a stray toggle left them in — and functionally,
 * this is often the *only* proof of purchase a guest checkout produces.
 *
 * ## Sent to guests as well as accounts
 *
 * `$notifiable` may be a `User`, or it may be an anonymous notifiable created
 * with `Illuminate\Notifications\AnonymousNotifiable` via
 * `Notification::route('mail', $order->customer_email)` — see OrderMailer for
 * which path a given order takes. The class itself does not care: every value
 * it needs comes from the `Order` in its constructor, never from `$notifiable`.
 */
final class OrderPlacedNotification extends Notification implements ShouldQueue
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
        return NotificationType::OrderPlaced;
    }

    /**
     * @return array<int, string>
     */
    protected function baseChannels(): array
    {
        // Database only for a real account — a guest notifiable has no row in
        // `users` or `admins` for a database notification to attach to.
        return $this->order->user_id !== null ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyName = $this->companyName();
        $order = $this->order;

        /*
         * `Illuminate\Queue\SerializesModels` re-fetches a fresh Order on the
         * queue worker — it does not carry relations loaded by whoever
         * constructed this notification. `Model::shouldBeStrict()` is on
         * outside production, so iterating `items` without this would throw a
         * LazyLoadingViolation the moment a queued job actually ran.
         */
        $order->loadMissing('items');

        $message = (new MailMessage)
            ->subject("Order confirmed — {$order->order_number}")
            ->greeting("Thank you, {$order->customer_name}!")
            ->line("We've received your order and it's being processed.")
            ->line("**Order number:** {$order->order_number}")
            ->line("**Order total:** {$this->money((int) $order->grand_total)}");

        foreach ($order->items as $item) {
            $message->line(sprintf(
                '%d × %s — %s',
                $item->quantity,
                $item->displayName(),
                $this->money((int) $item->line_total),
            ));
        }

        return $message
            ->line("**Payment method:** {$order->payment_method->label()}")
            ->action('View your order', $this->orderUrl($order))
            ->line('We will email you again once your order ships.')
            ->salutation("— The {$companyName} team");
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Order confirmed',
            'body' => "Your order {$this->order->order_number} has been received.",
            'order_id' => $this->order->uuid,
            'order_number' => $this->order->order_number,
        ];
    }

    private function orderUrl(Order $order): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->uuid}";
    }
}
