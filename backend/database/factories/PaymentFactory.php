<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'order_id' => Order::factory(),
            'method' => PaymentMethod::CashOnDelivery,
            'status' => Payment::STATUS_PENDING,
            'amount' => $this->faker->numberBetween(1_000, 100_000),
            'currency' => 'USD',
            'gateway' => null,
            'transaction_reference' => null,
            'card_brand' => null,
            'card_last_four' => null,
            'gateway_response' => null,
            'failure_reason' => null,
            'paid_at' => null,
            'failed_at' => null,
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (): array => [
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Card declined.'): self
    {
        return $this->state(fn (): array => [
            'status' => Payment::STATUS_FAILED,
            'failure_reason' => $reason,
            'failed_at' => now(),
        ]);
    }

    public function forOrder(Order $order): self
    {
        return $this->state(fn (): array => [
            'order_id' => $order->getKey(),
            'method' => $order->payment_method,
            'amount' => (int) $order->grand_total,
            'currency' => $order->currency,
        ]);
    }
}
