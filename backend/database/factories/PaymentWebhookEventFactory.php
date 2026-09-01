<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentWebhookEvent>
 */
final class PaymentWebhookEventFactory extends Factory
{
    protected $model = PaymentWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gateway' => 'stripe',
            'source' => PaymentWebhookEvent::SOURCE_WEBHOOK,

            // Unique by default: the table's whole purpose is a unique index on
            // (gateway, event_id), and a factory that produced collisions would
            // fail for reasons unrelated to the test.
            'event_id' => 'evt_'.Str::lower(Str::random(24)),
            'event_type' => 'payment.succeeded',
            'transaction_reference' => 'pi_'.Str::lower(Str::random(24)),

            'payment_id' => null,
            'order_id' => null,

            // Verified by default — the ordinary case. A test about rejection
            // asks for it explicitly, which keeps the interesting state visible
            // at the call site.
            'is_verified' => true,
            'is_processed' => false,
            'rejection_reason' => null,

            'payload' => ['type' => 'checkout.session.completed'],
            'ip_address' => '127.0.0.1',
            'processed_at' => null,
        ];
    }

    public function callback(): self
    {
        return $this->state(fn (): array => [
            'source' => PaymentWebhookEvent::SOURCE_CALLBACK,
            'event_type' => 'callback.success',
        ]);
    }

    /**
     * An event whose signature did not verify — a security record.
     */
    public function unverified(): self
    {
        return $this->state(fn (): array => [
            'is_verified' => false,
            'rejection_reason' => 'Signature verification failed.',
        ]);
    }

    public function processed(): self
    {
        return $this->state(fn (): array => [
            'is_processed' => true,
            'processed_at' => now(),
        ]);
    }

    public function forGateway(string $gateway): self
    {
        return $this->state(fn (): array => ['gateway' => $gateway]);
    }
}
