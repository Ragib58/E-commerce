<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Events\StockLevelLow;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Notifications\Concerns\RespectsNotificationPreference;
use App\Notifications\Concerns\UsesStoreBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts staff that a product or variant has fallen to or below its reorder
 * point.
 *
 * Built from a {@see StockLevelLow} event, which already fires
 * only on the transition *into* the low band — not on every subsequent sale
 * while it stays there. This class inherits that guarantee rather than
 * re-implementing it: an admin gets one alert per stockable per dip, not one
 * per order.
 */
final class AdminLowStockNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreference;
    use UsesStoreBranding;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly Product|ProductVariant $stockable,
        private readonly int $remaining,
        private readonly string $label,
    ) {
        $this->onQueue('notifications');
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::AdminLowStock;
    }

    /**
     * @return array<int, string>
     */
    protected function baseChannels(): array
    {
        // Database only by default — see AdminPaymentReceivedNotification for
        // why routine operational events do not default to email.
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isOutOfStock = $this->remaining <= 0;

        return (new MailMessage)
            ->subject($isOutOfStock ? "Out of stock — {$this->label}" : "Low stock — {$this->label}")
            ->greeting($isOutOfStock ? 'Out of stock' : 'Low stock alert')
            ->line("**{$this->label}**")
            ->line($isOutOfStock
                ? 'This item has sold out.'
                : "Only {$this->remaining} units remain.")
            ->action('Manage stock', $this->stockUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->remaining <= 0 ? 'Out of stock' : 'Low stock',
            'body' => $this->remaining <= 0
                ? "{$this->label} has sold out."
                : "{$this->label} has {$this->remaining} units remaining.",
            'product_id' => $this->productForRoute()?->uuid,
        ];
    }

    private function productForRoute(): ?Product
    {
        // loadMissing rather than a bare access: a queued run rehydrates this
        // model without the relation loaded, and Model::shouldBeStrict would
        // throw on an unloaded belongsTo outside production.
        if ($this->stockable instanceof ProductVariant) {
            $this->stockable->loadMissing('product');

            return $this->stockable->product;
        }

        return $this->stockable;
    }

    private function stockUrl(): string
    {
        $product = $this->productForRoute();
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $product !== null ? "{$base}/admin/products/{$product->uuid}" : "{$base}/admin/inventory";
    }
}
