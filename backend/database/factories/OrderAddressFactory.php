<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AddressType;
use App\Models\Order;
use App\Models\OrderAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderAddress>
 */
final class OrderAddressFactory extends Factory
{
    protected $model = OrderAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'type' => AddressType::Shipping,

            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'company' => null,

            'phone' => $this->faker->numerify('+1##########'),
            'email' => $this->faker->safeEmail(),

            'line1' => $this->faker->streetAddress(),
            'line2' => null,
            'city' => $this->faker->city(),
            'state' => $this->faker->stateAbbr(),
            'postal_code' => $this->faker->postcode(),

            // Upper-case ISO alpha-2, as CheckoutService normalises it. A
            // fixture in another case would let a case-sensitivity bug pass.
            'country' => 'US',

            'delivery_instructions' => null,
        ];
    }

    public function shipping(): self
    {
        return $this->state(fn (): array => ['type' => AddressType::Shipping]);
    }

    public function billing(): self
    {
        return $this->state(fn (): array => ['type' => AddressType::Billing]);
    }

    public function inCountry(string $code): self
    {
        return $this->state(fn (): array => ['country' => strtoupper($code)]);
    }
}
