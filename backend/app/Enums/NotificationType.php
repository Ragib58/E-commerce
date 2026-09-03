<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every notification this application can send, to either audience.
 *
 * The single vocabulary `NotificationPreferenceService`,
 * `notification_preferences`, and every notification class agree on. A type
 * that is not a case here cannot be checked against a preference, muted, or
 * listed in the account settings UI — which is what keeps the preference table
 * and the actual set of notifications from drifting apart.
 *
 * ## Customer types and admin types share one enum
 *
 * `OrderPlacedNotification` targets a customer; `NewOrderAdminNotification`
 * targets staff — different classes, different audiences, but the same
 * underlying *event*. Two enums would need a mapping between them the moment
 * anything asked "what does an order placement notify about", and that mapping
 * is exactly the kind of thing that quietly falls out of sync. {@see audience()}
 * is that answer instead, computed from the case rather than duplicated beside
 * it.
 */
enum NotificationType: string
{
    // Customer — account
    case Welcome = 'welcome';

    // Customer — order lifecycle
    case OrderPlaced = 'order_placed';
    case OrderConfirmed = 'order_confirmed';
    case OrderShipped = 'order_shipped';
    case OrderDelivered = 'order_delivered';
    case OrderCancelled = 'order_cancelled';

    // Customer — payment
    case PaymentSuccessful = 'payment_successful';
    case PaymentFailed = 'payment_failed';
    case RefundProcessed = 'refund_processed';

    // Admin
    case AdminNewOrder = 'admin_new_order';
    case AdminPaymentReceived = 'admin_payment_received';
    case AdminLowStock = 'admin_low_stock';
    case AdminFailedPayment = 'admin_failed_payment';

    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome email',
            self::OrderPlaced => 'Order placed',
            self::OrderConfirmed => 'Order confirmed',
            self::OrderShipped => 'Order shipped',
            self::OrderDelivered => 'Order delivered',
            self::OrderCancelled => 'Order cancelled',
            self::PaymentSuccessful => 'Payment successful',
            self::PaymentFailed => 'Payment failed',
            self::RefundProcessed => 'Refund processed',
            self::AdminNewOrder => 'New order received',
            self::AdminPaymentReceived => 'Payment received',
            self::AdminLowStock => 'Low stock alert',
            self::AdminFailedPayment => 'Failed payment alert',
        };
    }

    /**
     * A sentence explaining what the notification is for, shown next to its
     * toggle in account settings.
     */
    public function description(): string
    {
        return match ($this) {
            self::Welcome => 'Sent once, when you create your account.',
            self::OrderPlaced => 'Confirms we have received your order.',
            self::OrderConfirmed => 'Lets you know your order has been accepted and is being prepared.',
            self::OrderShipped => 'Sent when your order leaves our warehouse, with tracking details.',
            self::OrderDelivered => 'Sent when your order is marked delivered.',
            self::OrderCancelled => 'Confirms an order has been cancelled.',
            self::PaymentSuccessful => 'Confirms a payment was received.',
            self::PaymentFailed => 'Lets you know a payment attempt did not go through.',
            self::RefundProcessed => 'Confirms a refund has been issued.',
            self::AdminNewOrder => 'A new order has been placed on the store.',
            self::AdminPaymentReceived => 'A payment has been captured for an order.',
            self::AdminLowStock => 'A product has fallen to or below its reorder point.',
            self::AdminFailedPayment => 'A payment attempt failed.',
        };
    }

    /**
     * Which kind of account this notification is sent to.
     */
    public function audience(): NotificationAudience
    {
        return match ($this) {
            self::Welcome,
            self::OrderPlaced,
            self::OrderConfirmed,
            self::OrderShipped,
            self::OrderDelivered,
            self::OrderCancelled,
            self::PaymentSuccessful,
            self::PaymentFailed,
            self::RefundProcessed => NotificationAudience::Customer,

            self::AdminNewOrder,
            self::AdminPaymentReceived,
            self::AdminLowStock,
            self::AdminFailedPayment => NotificationAudience::Admin,
        };
    }

    /**
     * Whether an account may switch this notification off.
     *
     * Returning false here is what makes the "opt-out, not opt-in" design in
     * the preferences migration safe: a handful of types are checked nowhere
     * near a preference row, no matter what it contains, because being unable
     * to reach a customer about a failed charge or a placed order is worse than
     * one unwanted email.
     *
     * `Welcome` is also immutable for a simpler reason — there is no account to
     * hold a preference until the welcome email is the thing that establishes
     * one exists.
     */
    public function isMutable(): bool
    {
        return ! in_array($this, [
            self::Welcome,
            self::OrderPlaced,
            self::PaymentFailed,
            self::AdminFailedPayment,
        ], strict: true);
    }

    /**
     * The channels this notification is ever sent on.
     *
     * Admin alerts default to database-only — a badge in the panel is enough
     * for "stock is low", and defaulting every admin alert to email as well
     * would mean a busy day generates a mail storm nobody reads. Customer
     * notifications default to mail plus database, since the database copy is
     * what powers the account's notification centre.
     *
     * @return array<int, string>
     */
    public function defaultChannels(): array
    {
        return match ($this->audience()) {
            NotificationAudience::Customer => ['mail', 'database'],
            NotificationAudience::Admin => match ($this) {
                self::AdminNewOrder, self::AdminFailedPayment => ['mail', 'database'],
                default => ['database'],
            },
        };
    }

    /**
     * @return array<int, self>
     */
    public static function forAudience(NotificationAudience $audience): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->audience() === $audience,
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, is_mutable: bool}>
     */
    public static function options(NotificationAudience $audience): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
                'is_mutable' => $case->isMutable(),
            ],
            self::forAudience($audience),
        );
    }
}
