<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shopping cart, belonging to either a signed-in customer or a guest token.
 *
 * Note what this model does not have: any method returning a total. Money is
 * never read from a cart row — CartService recomputes every figure from the
 * catalog, and doing it there rather than here keeps the "prices come from one
 * place" rule enforceable by inspection.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $token
 * @property string|null $coupon_code
 * @property Carbon|null $last_activity_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CartItem> $items
 */
class Cart extends Model
{
    /** @use HasFactory<\Database\Factories\CartFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'coupon_code',
        'last_activity_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Lines in the cart, oldest first.
     *
     * Stable ordering matters more than it looks: without it, incrementing a
     * quantity can make a line jump position on the next render, and a shopper
     * clicking "+" twice ends up clicking a different row the second time.
     *
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->oldest('id');
    }

    /**
     * Lines with everything pricing needs already loaded.
     *
     * Every price and stock figure is derived from the product and its chosen
     * variant, so a cart read without these relations would issue two queries
     * per line — and under `Model::shouldBeStrict()` it would instead throw a
     * LazyLoadingViolation, which is the better failure.
     *
     * `variant.product` is included because a variant inherits price from its
     * parent when its own is null; without it the inheritance silently yields
     * zero.
     *
     * @return HasMany<CartItem, $this>
     */
    public function itemsForPricing(): HasMany
    {
        return $this->items()->with([
            'product' => fn ($query) => $query->with(['media' => fn ($media) => $media->where('is_thumbnail', true)->limit(1)]),
            'variant.product',
            'variant.attributeValues.attribute',
        ]);
    }

    /**
     * @param  Builder<Cart>  $query
     * @return Builder<Cart>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<Cart>  $query
     * @return Builder<Cart>
     */
    public function scopeForToken(Builder $query, string $token): Builder
    {
        return $query->where('token', $token);
    }

    /**
     * Guest carts nobody has touched since the cut-off.
     *
     * Read by the pruning command. Only guest carts are eligible: a signed-in
     * customer's cart is theirs to return to months later, whereas an abandoned
     * anonymous cart is unreachable by anyone once its cookie is gone.
     *
     * @param  Builder<Cart>  $query
     * @return Builder<Cart>
     */
    public function scopeAbandonedGuest(Builder $query, Carbon $before): Builder
    {
        return $query
            ->whereNull('user_id')
            ->where(fn (Builder $inner) => $inner
                ->where('last_activity_at', '<', $before)
                ->orWhere(fn (Builder $fallback) => $fallback
                    ->whereNull('last_activity_at')
                    ->where('created_at', '<', $before)));
    }

    /**
     * Stamp the cart as touched.
     *
     * Written with saveQuietly and only when the value actually changes to the
     * minute: every read of a cart would otherwise be a write, and a shopper
     * refreshing a page would generate a row update per request.
     */
    public function touchActivity(): void
    {
        $now = Carbon::now();

        if ($this->last_activity_at !== null && $this->last_activity_at->diffInMinutes($now) < 1) {
            return;
        }

        $this->forceFill(['last_activity_at' => $now])->saveQuietly();
    }
}
