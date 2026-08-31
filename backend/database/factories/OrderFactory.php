<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 *
 * Produces an order directly, bypassing the checkout pipeline — for tests about
 * *reading* and *transitioning* orders, where building a cart and walking seven
 * steps would be noise.
 *
 * Tests about **placement itself** must not use this. Idempotency, stock
 * locking, and server-side pricing are properties of OrderService, and a
 * factory-built order exercises none of them; asserting against one would be
 * asserting that the factory works.
 *
 * The money fields satisfy Order::totalsReconcile() by construction, so a test
 * that asserts an order's totals add up is testing the code under test rather
 * than an inconsistent fixture.
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(1_000, 100_000);
        $tax = (int) round($subtotal * 0.1);
        $shipping = 500;

        return [
            'uuid' => (string) Str::uuid(),

            // Shaped like OrderNumberGenerator's output, including the
            // unambiguous alphabet — a test asserting on the format should not
            // pass against a value that could never be generated.
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),

            'user_id' => null,
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->unique()->safeEmail(),
            'customer_phone' => $this->faker->numerify('+1##########'),
            'is_guest' => true,

            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
            'payment_method' => PaymentMethod::CashOnDelivery,

            'shipping_method_id' => null,
            'shipping_method_name' => 'Standard',

            'subtotal' => $subtotal,
            'discount_total' => 0,
            'tax_total' => $tax,
            'shipping_total' => $shipping,
            // The identity Order::totalsReconcile() asserts.
            'grand_total' => $subtotal + $tax + $shipping,
            'refunded_total' => 0,

            'currency' => 'USD',
            'tax_rate' => 10.0,

            'placed_at' => now(),
        ];
    }

    /**
     * Build the model with every attribute applied, fillable or not.
     *
     * `status`, `payment_status`, and the money columns are deliberately absent
     * from Order::$fillable — a mass-assignable total is a total something can
     * be told. Ordinary mass assignment would therefore silently *drop* them
     * here, leaving fixtures with a null status and zero totals: a confusing
     * failure rather than a clear one.
     *
     * `forceFill` is legitimate for a factory. It is constructing a fixture
     * already in a state, not transitioning an order through one — and
     * Order::booted()'s guard fires on `updating`, so an insert needs no
     * exemption anyway.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Order
    {
        $order = new Order;

        $order->forceFill($attributes);

        return $order;
    }

    /**
     * An order belonging to a registered customer.
     */
    public function forUser(User $user): self
    {
        return $this->state(fn (): array => [
            'user_id' => $user->getKey(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'is_guest' => false,
        ]);
    }

    /**
     * An order in a given status.
     *
     * Sets the column directly rather than transitioning into it: a fixture in
     * the Delivered state should not require six prior transitions and six
     * history rows a test did not ask for.
     */
    public function status(OrderStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function paymentStatus(PaymentStatus $status): self
    {
        return $this->state(fn (): array => ['payment_status' => $status]);
    }

    /**
     * A paid, confirmed order — the starting point for most fulfilment tests.
     */
    public function paid(): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function shipped(): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Shipped,
            'payment_status' => PaymentStatus::Paid,
            'confirmed_at' => now()->subDays(2),
            'shipped_at' => now(),
        ]);
    }

    public function delivered(): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Delivered,
            'payment_status' => PaymentStatus::Paid,
            'confirmed_at' => now()->subDays(4),
            'shipped_at' => now()->subDays(2),
            'delivered_at' => now(),
        ]);
    }

    /**
     * Explicit totals, for a test asserting exact figures.
     */
    public function totals(int $subtotal, int $tax = 0, int $shipping = 0, int $discount = 0): self
    {
        return $this->state(fn (): array => [
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'shipping_total' => $shipping,
            'discount_total' => $discount,
            'grand_total' => $subtotal + $tax + $shipping,
        ]);
    }
}
