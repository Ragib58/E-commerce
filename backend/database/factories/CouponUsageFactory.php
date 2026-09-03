<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CouponUsage>
 */
final class CouponUsageFactory extends Factory
{
    protected $model = CouponUsage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory(),
            'order_id' => Order::factory(),
            'user_id' => null,
            'customer_email' => $this->faker->unique()->safeEmail(),
            'coupon_code' => strtoupper($this->faker->bothify('SAVE####')),
            'discount_amount' => $this->faker->numberBetween(100, 5_000),
            'created_at' => now(),
        ];
    }

    public function forCoupon(Coupon $coupon): self
    {
        return $this->state(fn (): array => [
            'coupon_id' => $coupon->getKey(),
            'coupon_code' => $coupon->code,
        ]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => [
            'user_id' => $user->getKey(),
            'customer_email' => $user->email,
        ]);
    }
}
