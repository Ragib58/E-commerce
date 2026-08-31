<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\SettingsService;

/**
 * How the customer intends to pay.
 *
 * An enum rather than a table because the *integration* for each method is
 * code — a Stripe charge and a bank transfer share no implementation — so a row
 * an admin could insert would name a gateway that does not exist. Which methods
 * are *offered* is still configuration: {@see enabledFor()} reads store
 * settings, so switching a method off is an admin toggle, not a deploy.
 *
 * No gateway is wired up in this phase. Offline methods (cash on delivery, bank
 * transfer) are fully functional because they need no integration; online
 * methods are declared, validated, and routed to a deliberate "not configured"
 * failure rather than a silent success. An order that reports itself Paid
 * because nothing rejected it is the worst available outcome.
 */
enum PaymentMethod: string
{
    /** Pay the courier on delivery. */
    case CashOnDelivery = 'cash_on_delivery';

    /** Manual bank transfer against the order number. */
    case BankTransfer = 'bank_transfer';

    /** Card, via the configured gateway. */
    case Card = 'card';

    /** Digital wallet — bKash, Nagad, and similar. */
    case MobileWallet = 'mobile_wallet';

    /** PayPal. */
    case PayPal = 'paypal';

    /** Store credit or a gift card balance. */
    case StoreCredit = 'store_credit';

    public function label(): string
    {
        return match ($this) {
            self::CashOnDelivery => 'Cash on delivery',
            self::BankTransfer => 'Bank transfer',
            self::Card => 'Credit or debit card',
            self::MobileWallet => 'Mobile wallet',
            self::PayPal => 'PayPal',
            self::StoreCredit => 'Store credit',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CashOnDelivery => 'Pay in cash when your order arrives.',
            self::BankTransfer => 'Transfer the total to our account. We ship once it clears.',
            self::Card => 'Pay securely by card.',
            self::MobileWallet => 'Pay from your mobile wallet balance.',
            self::PayPal => 'Pay with your PayPal account.',
            self::StoreCredit => 'Use your available store credit.',
        };
    }

    /**
     * Whether payment is collected outside the application.
     *
     * Offline methods place the order immediately with payment Pending — there
     * is nothing to charge and nothing to wait for. Online methods must not
     * reach a Paid state until a gateway says so.
     */
    public function isOffline(): bool
    {
        return in_array($this, [self::CashOnDelivery, self::BankTransfer], strict: true);
    }

    /**
     * Whether this method needs a payment gateway that this phase does not yet
     * provide.
     *
     * Read by CheckoutService, which refuses these with an explicit message.
     * The alternative — accepting the order and marking it Pending — looks
     * identical to a working integration until a shopper asks why they were
     * never charged.
     */
    public function requiresGateway(): bool
    {
        return in_array($this, [self::Card, self::MobileWallet, self::PayPal], strict: true);
    }

    /**
     * Whether an order paid this way may be confirmed the moment it is placed.
     *
     * Cash on delivery can: the store has agreed to ship before being paid.
     * Bank transfer cannot — nothing has cleared, and confirming would put an
     * unpaid order into the picking queue.
     */
    public function confirmsImmediately(): bool
    {
        return $this === self::CashOnDelivery;
    }

    /**
     * The store setting that switches this method on.
     */
    public function settingKey(): string
    {
        return 'payment.'.$this->value.'_enabled';
    }

    /**
     * Whether the method is currently offered, per store settings.
     *
     * Cash on delivery defaults to enabled and everything else to disabled:
     * the safe default for an unconfigured store is the method that cannot
     * silently fail to take money.
     */
    public function isEnabled(SettingsService $settings): bool
    {
        $default = $this === self::CashOnDelivery;

        return (bool) $settings->get($this->settingKey(), $default);
    }

    /**
     * The methods a shopper may currently choose between.
     *
     * @return array<int, self>
     */
    public static function enabledFor(SettingsService $settings): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->isEnabled($settings),
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
     * @return array<int, array{value: string, label: string, description: string, is_offline: bool}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
                'is_offline' => $case->isOffline(),
            ],
            self::cases(),
        );
    }
}
