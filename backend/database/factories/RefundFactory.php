<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 *
 * Builds a refund *row*. It does not move `orders.refunded_total`, which
 * RefundService maintains inside the same transaction as the real thing — a
 * factory that updated it would make a test asserting the two stay in step pass
 * regardless of whether the service does its job.
 */
final class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'order_id' => Order::factory(),
            'payment_id' => null,
            'amount' => $this->faker->numberBetween(500, 10_000),
            'currency' => 'USD',
            'status' => Refund::STATUS_COMPLETED,
            'admin_id' => null,
            'actor_label' => 'System',
            'reason' => $this->faker->sentence(6),
            'line_items' => null,
            'is_restocked' => false,
            'gateway' => null,
            'transaction_reference' => null,
            'gateway_response' => null,
            'failure_reason' => null,
            'refunded_at' => now(),
        ];
    }

    public function amount(int $minorUnits): self
    {
        return $this->state(fn (): array => ['amount' => $minorUnits]);
    }

    public function pending(): self
    {
        return $this->state(fn (): array => [
            'status' => Refund::STATUS_PENDING,
            'refunded_at' => null,
        ]);
    }

    public function restocked(): self
    {
        return $this->state(fn (): array => ['is_restocked' => true]);
    }
}
