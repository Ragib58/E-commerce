<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note on an order's running thread.
 *
 * `is_customer_visible` decides whether it reaches the shopper's order page,
 * and it defaults to false at the database level. See the migration: an
 * internal note surfaced to a customer is a serious incident, so "forgot to set
 * the flag" must fail closed.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $admin_id
 * @property int|null $user_id
 * @property string|null $author_label
 * @property string $body
 * @property bool $is_customer_visible
 * @property bool $notified_customer
 */
class OrderNote extends Model
{
    /** @use HasFactory<OrderNoteFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'admin_id',
        'user_id',
        'author_label',
        'body',
        'is_customer_visible',
        'notified_customer',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_customer_visible' => 'boolean',
            'notified_customer' => 'boolean',
        ];
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
     * Notes a customer may read.
     *
     * @param  Builder<OrderNote>  $query
     * @return Builder<OrderNote>
     */
    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('is_customer_visible', true);
    }

    /**
     * Whether this note was written by the shopper rather than by staff.
     */
    public function isFromCustomer(): bool
    {
        return $this->user_id !== null && $this->admin_id === null;
    }

    public function authorName(): string
    {
        return $this->author_label ?? 'System';
    }
}
