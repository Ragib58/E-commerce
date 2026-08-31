<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CheckoutStep;
use App\Enums\PaymentMethod;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckoutSession>
 *
 * A checkout in progress. {@see completedThrough()} is the useful entry point:
 * most tests care about a session that has reached a particular step, not about
 * one built field by field.
 */
final class CheckoutSessionFactory extends Factory
{
    protected $model = CheckoutSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // 64 hex characters, matching what CheckoutService mints and what
            // the route's segment constraint accepts.
            'token' => bin2hex(random_bytes(32)),
            'cart_id' => Cart::factory(),
            'user_id' => null,
            'data' => [],
            'current_step' => CheckoutStep::Customer->value,
            'order_id' => null,
            'completed_at' => null,
            'expires_at' => now()->addHours(24),
            'ip_address' => '127.0.0.1',
        ];
    }

    public function forCart(Cart $cart): self
    {
        return $this->state(fn (): array => [
            'cart_id' => $cart->getKey(),
            'user_id' => $cart->user_id,
        ]);
    }

    /**
     * A session whose steps are filled in up to and including the given one.
     *
     * The payload shape mirrors what CheckoutService writes, so a test that
     * jumps to step five is exercising the same session structure the real
     * flow produces — a fixture with a different shape would let a key-name
     * mismatch pass unnoticed.
     */
    public function completedThrough(CheckoutStep $step, ?ShippingMethod $shippingMethod = null): self
    {
        return $this->state(function () use ($step, $shippingMethod): array {
            $data = [];
            $position = $step->position();

            if ($position >= CheckoutStep::Customer->position()) {
                $data['customer'] = [
                    'name' => 'Test Customer',
                    'email' => 'customer@example.test',
                    'phone' => '+15550000000',
                ];
            }

            if ($position >= CheckoutStep::ShippingAddress->position()) {
                $data['shipping_address'] = $this->addressPayload();
            }

            if ($position >= CheckoutStep::BillingAddress->position()) {
                $data['billing_same_as_shipping'] = true;
                $data['billing_address'] = null;
            }

            if ($position >= CheckoutStep::ShippingMethod->position()) {
                $method = $shippingMethod ?? ShippingMethod::factory()->create();
                $data['shipping_method_id'] = $method->getKey();
            }

            if ($position >= CheckoutStep::PaymentMethod->position()) {
                // Cash on delivery: the only method that needs no gateway and
                // therefore the only one a test can place an order with.
                $data['payment_method'] = PaymentMethod::CashOnDelivery->value;
                $data['customer_note'] = null;
            }

            if ($position >= CheckoutStep::Review->position()) {
                $data['reviewed_at'] = now()->toIso8601String();
            }

            return [
                'data' => $data,
                'current_step' => CheckoutStep::firstIncomplete($data)->value,
            ];
        });
    }

    /**
     * A session ready for the final POST.
     */
    public function readyToPlace(?ShippingMethod $shippingMethod = null): self
    {
        return $this->completedThrough(CheckoutStep::Review, $shippingMethod);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function addressPayload(): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'company' => null,
            'phone' => '+15550000000',
            'email' => 'customer@example.test',
            'line1' => '1 Test Street',
            'line2' => null,
            'city' => 'Testville',
            'state' => 'TS',
            'postal_code' => '12345',
            'country' => 'US',
            'delivery_instructions' => null,
        ];
    }
}
