<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Coupon>
 */
final class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'code' => strtoupper(Str::random(8)),
            'name' => $this->faker->words(3, true),
            'description' => null,
            'type' => CouponType::Percentage,
            'value' => 10.0,
            'max_discount' => null,
            'min_order_amount' => null,
            'free_shipping' => false,
            'applies_to_all' => true,
            'first_order_only' => false,
            'user_restricted' => false,
            'starts_at' => null,
            'expires_at' => null,
            'usage_limit' => null,
            'per_user_limit' => null,
            'used_count' => 0,
            'is_active' => true,
            'is_public' => false,
            'created_by' => null,
        ];
    }

    public function percentage(float $percent, ?int $maxDiscount = null): self
    {
        return $this->state(fn (): array => [
            'type' => CouponType::Percentage,
            'value' => $percent,
            'max_discount' => $maxDiscount,
        ]);
    }

    public function fixed(int $minorUnits): self
    {
        return $this->state(fn (): array => [
            'type' => CouponType::Fixed,
            'value' => (float) $minorUnits,
            'max_discount' => null,
        ]);
    }

    public function minOrderAmount(int $minorUnits): self
    {
        return $this->state(fn (): array => ['min_order_amount' => $minorUnits]);
    }

    public function freeShipping(): self
    {
        return $this->state(fn (): array => ['free_shipping' => true]);
    }

    public function firstOrderOnly(): self
    {
        return $this->state(fn (): array => ['first_order_only' => true]);
    }

    public function userRestricted(): self
    {
        return $this->state(fn (): array => ['user_restricted' => true]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function notYetStarted(): self
    {
        return $this->state(fn (): array => ['starts_at' => now()->addDay()]);
    }

    public function usageLimit(int $limit): self
    {
        return $this->state(fn (): array => ['usage_limit' => $limit]);
    }

    public function perUserLimit(int $limit): self
    {
        return $this->state(fn (): array => ['per_user_limit' => $limit]);
    }

    public function used(int $count): self
    {
        return $this->state(fn (): array => ['used_count' => $count]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function public(): self
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }

    public function notApplicableToAll(): self
    {
        return $this->state(fn (): array => ['applies_to_all' => false]);
    }
}
