<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
final class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $notifiable = User::factory()->create();

        return [
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'type' => NotificationType::OrderShipped->value,
            'channel' => 'mail',
            'is_enabled' => false,
        ];
    }

    public function for(Admin|User $notifiable): self
    {
        return $this->state(fn (): array => [
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
        ]);
    }

    public function type(NotificationType $type): self
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }

    public function channel(string $channel): self
    {
        return $this->state(fn (): array => ['channel' => $channel]);
    }

    public function enabled(): self
    {
        return $this->state(fn (): array => ['is_enabled' => true]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }
}
