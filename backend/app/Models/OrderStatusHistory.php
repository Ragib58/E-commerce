<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in an order's audit trail.
 *
 * **Append-only, enforced here rather than merely documented.** The same
 * discipline StockMovement follows, for the same reason: a history row that can
 * be edited turns "who cancelled this order and when" from a fact into an
 * assertion, and a tamperable audit trail is worse than none — it looks
 * authoritative while being wrong.
 *
 * A mistake is corrected by appending a further transition, never by editing
 * what was written.
 *
 * @property int $id
 * @property int $order_id
 * @property string $stream
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $admin_id
 * @property int|null $user_id
 * @property string|null $actor_label
 * @property string|null $comment
 * @property bool $notified_customer
 * @property Carbon|null $created_at
 */
class OrderStatusHistory extends Model
{
    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    protected $table = 'order_status_history';

    /** Rows are immutable, so there is no `updated_at` to maintain. */
    public const UPDATED_AT = null;

    /** The two streams recorded in this table. */
    public const STREAM_ORDER = 'order';

    public const STREAM_PAYMENT = 'payment';

    protected $fillable = [
        'order_id',
        'stream',
        'from_status',
        'to_status',
        'admin_id',
        'user_id',
        'actor_label',
        'comment',
        'notified_customer',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notified_customer' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Refuse updates and deletes outright.
     *
     * Returning false from these hooks cancels the operation. Enforced at the
     * model rather than by convention, so a seeder, a console command, or a
     * future controller cannot quietly rewrite history — the failure is loud
     * and immediate rather than a silently mutated audit trail.
     */
    protected static function booted(): void
    {
        static::updating(static function (self $entry): bool {
            throw new \LogicException(
                'Order status history is append-only. Correct a mistake by recording a further transition.',
            );
        });

        static::deleting(static function (self $entry): bool {
            throw new \LogicException(
                'Order status history is append-only and cannot be deleted.',
            );
        });
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who made this change, for display.
     *
     * Reads the denormalised label rather than the relation, so it still
     * answers correctly after the account has been deleted — which is exactly
     * when a timeline is most likely to be examined.
     */
    public function actorName(): string
    {
        return $this->actor_label ?? 'System';
    }

    public function isPaymentStream(): bool
    {
        return $this->stream === self::STREAM_PAYMENT;
    }
}
