<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The seven steps of checkout, in order.
 *
 * ## Why the server owns the step sequence
 *
 * The obvious implementation keeps the current step in the client and posts
 * everything at the end. That makes the step order a frontend concern, which
 * means a crafted request can post straight to "place order" having skipped
 * shipping selection — and the order is created with a null shipping cost.
 *
 * Here the server holds the checkout session, and {@see isSatisfiedBy()} decides
 * whether each step's data is actually present. The client is told which step
 * to render; it does not decide. A request that jumps ahead is refused with the
 * step it must complete first, so skipping is not a state the system can enter
 * rather than one it detects afterwards.
 *
 * The steps are also *resumable*: a shopper who closes the tab at payment
 * returns to the session as they left it, because every step's answer was
 * persisted when it was given rather than held in a form until the end.
 */
enum CheckoutStep: string
{
    /** Name, email, phone — and whether this is a guest or an account. */
    case Customer = 'customer';

    /** Where the goods go. */
    case ShippingAddress = 'shipping_address';

    /** Where the invoice goes. May be a copy of the shipping address. */
    case BillingAddress = 'billing_address';

    /** Which delivery service, and therefore what shipping costs. */
    case ShippingMethod = 'shipping_method';

    /** How the customer intends to pay. */
    case PaymentMethod = 'payment_method';

    /** The priced order, shown in full before anything is committed. */
    case Review = 'review';

    /** Commit. The only step that writes an order. */
    case Place = 'place';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Your details',
            self::ShippingAddress => 'Delivery address',
            self::BillingAddress => 'Billing address',
            self::ShippingMethod => 'Delivery method',
            self::PaymentMethod => 'Payment',
            self::Review => 'Review your order',
            self::Place => 'Place order',
        };
    }

    /**
     * 1-based position, for "step 3 of 7".
     */
    public function position(): int
    {
        return match ($this) {
            self::Customer => 1,
            self::ShippingAddress => 2,
            self::BillingAddress => 3,
            self::ShippingMethod => 4,
            self::PaymentMethod => 5,
            self::Review => 6,
            self::Place => 7,
        };
    }

    public static function total(): int
    {
        return count(self::cases());
    }

    /**
     * Whether a checkout session has the data this step exists to collect.
     *
     * The completeness rule for each step lives here and nowhere else, so the
     * progress indicator, the "can you advance?" check, and the guard on
     * placing an order all read the same definition and cannot disagree.
     *
     * @param  array<string, mixed>  $session  The stored checkout session payload.
     */
    public function isSatisfiedBy(array $session): bool
    {
        return match ($this) {
            self::Customer => ($session['customer']['email'] ?? null) !== null
                && ($session['customer']['name'] ?? null) !== null,

            self::ShippingAddress => ($session['shipping_address'] ?? null) !== null,

            /*
             * Satisfied by an explicit `billing_same_as_shipping` flag as well
             * as by a distinct address. Most shoppers use one address, and
             * making them retype it is the friction that loses checkouts — but
             * the flag has to be *set*, not merely absent, or a skipped step
             * would look identical to a deliberate choice.
             */
            self::BillingAddress => ($session['billing_address'] ?? null) !== null
                || ($session['billing_same_as_shipping'] ?? false) === true,

            self::ShippingMethod => ($session['shipping_method_id'] ?? null) !== null,

            self::PaymentMethod => ($session['payment_method'] ?? null) !== null,

            /*
             * Review is satisfied once the shopper has seen the priced order.
             * Recorded rather than assumed: "you agreed to this total" is a
             * claim the store may have to defend, and it is only true if the
             * total was actually rendered to them.
             */
            self::Review => ($session['reviewed_at'] ?? null) !== null,

            // Never "already satisfied" — placing is the action, not a stored
            // answer, and marking it complete in advance would make the guard
            // on the final POST pass for a session that never placed anything.
            self::Place => false,
        };
    }

    /**
     * Steps that must be complete before this one may be attempted.
     *
     * @return array<int, self>
     */
    public function prerequisites(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => $case->position() < $this->position(),
        ));
    }

    /**
     * The first step whose data is missing — where the shopper should be sent.
     *
     * @param  array<string, mixed>  $session
     */
    public static function firstIncomplete(array $session): self
    {
        foreach (self::cases() as $step) {
            if (! $step->isSatisfiedBy($session)) {
                return $step;
            }
        }

        return self::Place;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
