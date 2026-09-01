<?php

declare(strict_types=1);
use App\Payments\Gateways\BkashGateway;
use App\Payments\Gateways\CashOnDeliveryGateway;
use App\Payments\Gateways\SslCommerzGateway;
use App\Payments\Gateways\StripeGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway Registry
    |--------------------------------------------------------------------------
    |
    | Maps a gateway's identifier to the class implementing it. This array is
    | the ONLY place the application names a concrete gateway — PaymentService,
    | the controllers, and OrderService all resolve through PaymentGatewayManager
    | and never mention SSLCommerz or Stripe by name.
    |
    | That is what makes the architecture extensible in the way the brief asks
    | for: adding a gateway is a new class implementing PaymentGatewayInterface
    | plus one line here. No core order logic changes, because no core order
    | logic knows which gateway it is talking to.
    |
    | Keys are the stored payments.gateway value. Never rename one in place:
    | historical rows carry it, and a renamed key orphans them.
    |
    */

    'gateways' => [
        'cash_on_delivery' => CashOnDeliveryGateway::class,
        'sslcommerz' => SslCommerzGateway::class,
        'bkash' => BkashGateway::class,
        'stripe' => StripeGateway::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------------
    |
    | Used when an order names no gateway. Cash on delivery, deliberately: the
    | safe default for an unconfigured store is the one method that cannot
    | silently fail to take money.
    |
    */

    'default' => env('PAYMENT_DEFAULT_GATEWAY', 'cash_on_delivery'),

    /*
    |--------------------------------------------------------------------------
    | Method to Gateway Mapping
    |--------------------------------------------------------------------------
    |
    | A shopper chooses a *method* at checkout ("card", "mobile wallet"); a
    | *gateway* is what processes it. This array is where the two vocabularies
    | meet, and keeping it as configuration is what lets a store switch its card
    | processor from Stripe to SSLCommerz without touching code.
    |
    | Keys are App\Enums\PaymentMethod values; values are gateway identifiers.
    | A method absent from here falls back to a gateway of the same name, then
    | to the default.
    |
    */

    'method_gateways' => [
        'cash_on_delivery' => env('PAYMENT_GATEWAY_FOR_COD', 'cash_on_delivery'),
        'card' => env('PAYMENT_GATEWAY_FOR_CARD', 'stripe'),
        'mobile_wallet' => env('PAYMENT_GATEWAY_FOR_WALLET', 'bkash'),

        /*
         * Bank transfer is offline and has no processor. It maps to the cash
         * gateway, which reports Pending until a human records that the money
         * arrived — exactly the behaviour a manual transfer needs.
         */
        'bank_transfer' => env('PAYMENT_GATEWAY_FOR_BANK_TRANSFER', 'cash_on_delivery'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Every secret comes from the environment and nothing else. These are not
    | store settings: an admin panel field holding a Stripe secret key would put
    | it in the database, in backups, and in front of anyone holding
    | manage_settings — whereas branding, which IS a settings row, harms nobody
    | if read.
    |
    | The enabled flag is what switches a gateway on. It defaults to false for
    | every remote gateway so a fresh install cannot offer a payment method
    | whose credentials are absent — the failure mode being an order that looks
    | paid and never was.
    |
    */

    'sslcommerz' => [
        'enabled' => (bool) env('SSLCOMMERZ_ENABLED', false),
        'store_id' => env('SSLCOMMERZ_STORE_ID'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),

        /*
         * Sandbox and live are different hosts, not a flag on one host. Keeping
         * them as separate base URLs means a misconfigured environment fails to
         * connect rather than quietly charging real cards from a staging box.
         */
        'sandbox' => (bool) env('SSLCOMMERZ_SANDBOX', true),
        'sandbox_url' => env('SSLCOMMERZ_SANDBOX_URL', 'https://sandbox.sslcommerz.com'),
        'live_url' => env('SSLCOMMERZ_LIVE_URL', 'https://securepay.sslcommerz.com'),
    ],

    'bkash' => [
        'enabled' => (bool) env('BKASH_ENABLED', false),
        'app_key' => env('BKASH_APP_KEY'),
        'app_secret' => env('BKASH_APP_SECRET'),
        'username' => env('BKASH_USERNAME'),
        'password' => env('BKASH_PASSWORD'),
        'sandbox' => (bool) env('BKASH_SANDBOX', true),
        'sandbox_url' => env('BKASH_SANDBOX_URL', 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'),
        'live_url' => env('BKASH_LIVE_URL', 'https://tokenized.pay.bka.sh/v1.2.0-beta'),

        /*
         * bKash grant tokens are short-lived and rate limited, so they are
         * cached. The TTL is deliberately shorter than the token's own lifetime
         * — a token that expires between our cache read and the gateway's
         * validation would surface as an authentication failure mid-payment.
         */
        'token_cache_ttl' => (int) env('BKASH_TOKEN_CACHE_TTL', 3000),
    ],

    'stripe' => [
        'enabled' => (bool) env('STRIPE_ENABLED', false),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),

        /*
         * The webhook signing secret. Without it a webhook cannot be verified,
         * and an unverified webhook is an unauthenticated request claiming an
         * order was paid — so StripeGateway refuses to process one rather than
         * trusting it.
         */
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Return URLs
    |--------------------------------------------------------------------------
    |
    | Where a gateway sends the shopper's browser back to. These point at the
    | Next.js storefront, not at the API: the customer should land on a page,
    | not on a JSON document.
    |
    | The browser's return is a NAVIGATION, never a source of truth. Whatever
    | the gateway puts in the query string is treated as a hint that a payment
    | may have completed; the status is then established by a server-to-server
    | verification call. See PaymentService::handleCallback.
    |
    */

    'return_urls' => [
        'success' => env('PAYMENT_SUCCESS_URL', env('FRONTEND_URL', 'http://localhost:3000').'/checkout/success'),
        'failure' => env('PAYMENT_FAILURE_URL', env('FRONTEND_URL', 'http://localhost:3000').'/checkout/failed'),
        'cancel' => env('PAYMENT_CANCEL_URL', env('FRONTEND_URL', 'http://localhost:3000').'/checkout/cancelled'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Timeouts for outbound gateway calls. Short by design: a shopper waiting on
    | a hung connection is a shopper who reloads and tries to pay twice, and the
    | verification path has to finish inside a web request.
    |
    */

    'http' => [
        'timeout' => (int) env('PAYMENT_HTTP_TIMEOUT', 20),
        'connect_timeout' => (int) env('PAYMENT_HTTP_CONNECT_TIMEOUT', 10),
        'retries' => (int) env('PAYMENT_HTTP_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    |
    | require_amount_match rejects a verification whose amount disagrees with
    | the order's. Leaving it off would let a tampered or mis-routed callback
    | settle a 500.00 order with a 5.00 payment.
    |
    | The tolerance exists for gateways that round in a different minor unit; it
    | is zero by default, because "close enough" is not a property money has.
    |
    */

    'verification' => [
        'require_amount_match' => (bool) env('PAYMENT_REQUIRE_AMOUNT_MATCH', true),
        'amount_tolerance' => (int) env('PAYMENT_AMOUNT_TOLERANCE', 0),
    ],

];
