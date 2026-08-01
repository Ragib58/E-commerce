<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Hashed once per process rather than per row; bcrypt is
            // deliberately slow and dominates factory runtime otherwise.
            'password' => static::$passwordHash ??= Hash::make('password'),
            'phone' => null,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    protected static ?string $passwordHash = null;

    public function unverified(): self
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Set a known password for tests that need to authenticate.
     */
    public function withPassword(string $password): self
    {
        return $this->state(fn (): array => ['password' => Hash::make($password)]);
    }
}
