<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
final class CartFactory extends Factory
{
    protected $model = Cart::class;

    /**
     * A guest cart by default, since that is the state every shopper starts in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            // 64 hex characters, matching what CartService mints and what the
            // middleware's shape check accepts.
            'token' => bin2hex(random_bytes(32)),
            'coupon_code' => null,
            'last_activity_at' => now(),
        ];
    }

    /**
     * A cart belonging to a signed-in customer.
     *
     * The token is cleared, mirroring the service: a user's cart is found by id
     * and must not be reachable by a header, or a leaked cookie would expose an
     * account's basket.
     */
    public function forUser(User $user): self
    {
        return $this->state(fn (): array => [
            'user_id' => $user->getKey(),
            'token' => null,
        ]);
    }
}
