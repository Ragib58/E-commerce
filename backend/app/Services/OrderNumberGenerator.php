<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use RuntimeException;

/**
 * Generates the human-readable order reference.
 *
 * ## Why not a sequence
 *
 * The obvious implementation is `ORD-` plus an incrementing counter, and it is
 * wrong here for two reasons that compound.
 *
 * **It leaks.** A customer holding `ORD-000412` knows the store has taken about
 * four hundred orders, and one who orders twice a month can measure the growth
 * rate. That is competitive information given away for nothing.
 *
 * **It is guessable, and guessability has teeth in this system.** Guest orders
 * have no account behind them, so the order number *is* half the credential for
 * looking one up — number plus email. A sequential reference turns "look up my
 * order" into an enumeration attack against every recent order in the store.
 *
 * So the reference carries a date part, which is genuinely useful to support
 * staff, and a random part with enough entropy that walking it is not viable.
 *
 * ## Collisions
 *
 * The random part can repeat. The unique index on `orders.order_number` is what
 * actually guarantees uniqueness — this class only reduces how often the
 * database has to say no. {@see generate()} retries on collision and gives up
 * loudly rather than returning a duplicate that would fail at INSERT with a
 * less obvious error.
 */
final class OrderNumberGenerator
{
    /**
     * Characters the random part is drawn from.
     *
     * Crockford-style: no I, O, U, or 0/1, so a customer reading a number over
     * the phone cannot produce an ambiguous transcription. A support call that
     * fails because "was that an O or a zero?" is a real cost, and the entropy
     * given up is worth it.
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Length of the random part.
     *
     * 30^6 ≈ 7.3e8 combinations within a single day's prefix. At any plausible
     * order volume the birthday-collision probability stays negligible, and the
     * unique index catches what is left.
     */
    private const RANDOM_LENGTH = 6;

    /** Attempts before giving up. Reaching this means something is very wrong. */
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * A fresh, unused order number.
     *
     * Shape: `ORD-20260831-K7M2QX`.
     *
     * @throws RuntimeException when a unique value could not be found.
     */
    public function generate(): string
    {
        $prefix = $this->prefix();
        $date = now()->format('Ymd');

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = sprintf('%s-%s-%s', $prefix, $date, $this->randomPart());

            /*
             * A check, not a guarantee. Two requests can both pass this and
             * race to INSERT; the unique index decides. OrderService catches
             * that violation and retries with a new number, so this lookup is
             * an optimisation that keeps the retry path cold rather than the
             * mechanism uniqueness rests on.
             */
            if (! Order::query()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf(
            'Could not generate a unique order number after %d attempts.',
            self::MAX_ATTEMPTS,
        ));
    }

    /**
     * The invoice reference for an order.
     *
     * Derived from the order number rather than being a second independent
     * sequence: an invoice and its order are the same document to everyone who
     * handles them, and two unrelated references would mean every support
     * conversation begins by establishing which one the customer is reading.
     */
    public function invoiceNumber(Order $order): string
    {
        $prefix = (string) $this->settings->get('business.invoice_prefix', 'INV');

        // The order number's own prefix is stripped so the result reads
        // `INV-20260831-K7M2QX` rather than `INV-ORD-20260831-K7M2QX`.
        $body = preg_replace('/^[A-Z0-9]+-/', '', $order->order_number) ?? $order->order_number;

        return sprintf('%s-%s', $this->normalisePrefix($prefix, 'INV'), $body);
    }

    /**
     * The store's configured order prefix.
     */
    private function prefix(): string
    {
        return $this->normalisePrefix(
            (string) $this->settings->get('business.order_prefix', 'ORD'),
            'ORD',
        );
    }

    /**
     * Reduce an admin-supplied prefix to something safe for a reference.
     *
     * The value comes from a settings field an operator can type anything into,
     * and it ends up in URLs, filenames, and printed documents. Constraining it
     * here means a prefix containing a slash or a space cannot produce an order
     * number that breaks a download filename or a route.
     */
    private function normalisePrefix(string $prefix, string $fallback): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?? '');

        if ($clean === '') {
            return $fallback;
        }

        return substr($clean, 0, 8);
    }

    /**
     * The random component.
     *
     * `random_int` rather than `rand` or `mt_rand`: this value is part of a
     * credential for guest order lookup, and a predictable PRNG would make the
     * whole design pointless.
     */
    private function randomPart(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
