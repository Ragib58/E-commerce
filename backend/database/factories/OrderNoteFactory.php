<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderNote>
 *
 * Internal by default, matching the column default and the service. A factory
 * that produced customer-visible notes would make the disclosure tests pass by
 * accident — the interesting assertion is that an internal note stays internal.
 */
final class OrderNoteFactory extends Factory
{
    protected $model = OrderNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'admin_id' => null,
            'user_id' => null,
            'author_label' => 'System',
            'body' => $this->faker->sentence(12),
            'is_customer_visible' => false,
            'notified_customer' => false,
        ];
    }

    public function customerVisible(): self
    {
        return $this->state(fn (): array => ['is_customer_visible' => true]);
    }

    public function internal(): self
    {
        return $this->state(fn (): array => ['is_customer_visible' => false]);
    }

    public function byAdmin(Admin $admin): self
    {
        return $this->state(fn (): array => [
            'admin_id' => $admin->getKey(),
            'author_label' => $admin->name,
        ]);
    }
}
