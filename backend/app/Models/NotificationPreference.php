<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\NotificationPreferenceService;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One account's override for one notification type on one channel.
 *
 * A row only ever exists because an account changed a default — see the
 * migration for why absence means "on" rather than "off". Read and written
 * exclusively through {@see NotificationPreferenceService};
 * nothing else in the application should query this table directly, since the
 * service is what applies the immutable-type short-circuit before a row is
 * ever consulted.
 *
 * @property int $id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property string $type
 * @property string $channel
 * @property bool $is_enabled
 */
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'type',
        'channel',
        'is_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
