<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product a customer has saved for later.
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 */
class WishlistItem extends Model
{
    /** @use HasFactory<\Database\Factories\WishlistItemFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * One customer's saved products, newest first.
     *
     * Eager-loads exactly what a product card renders. A wishlist page is a
     * grid of cards, so the alternative is one query per saved item.
     *
     * @param  Builder<WishlistItem>  $query
     * @return Builder<WishlistItem>
     */
    public function scopeForListing(Builder $query, int $userId): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->whereHas('product', fn (Builder $product) => $product->published())
            ->with(['product' => fn ($product) => $product->withListingRelations()])
            ->latest('created_at');
    }
}
