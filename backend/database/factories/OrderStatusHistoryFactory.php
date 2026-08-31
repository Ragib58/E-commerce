<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusHistory>
 */
final class OrderStatusHistoryFactory extends Factory
{
    protected $model = OrderStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'stream' => OrderStatusHistory::STREAM_ORDER,
            // Null on the first row: the order came from nowhere.
            'from_status' => null,
            'to_status' => OrderStatus::Pending->value,
            'admin_id' => null,
            'user_id' => null,
            'actor_label' => 'System',
            'comment' => null,
            'notified_customer' => false,
            'created_at' => now(),
        ];
    }

    public function transition(OrderStatus $from, OrderStatus $to): self
    {
        return $this->state(fn (): array => [
            'from_status' => $from->value,
            'to_status' => $to->value,
        ]);
    }

    public function paymentStream(): self
    {
        return $this->state(fn (): array => ['stream' => OrderStatusHistory::STREAM_PAYMENT]);
    }
}
