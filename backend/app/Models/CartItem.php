<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a cart: a product, optionally a variant, and a quantity.
 *
 * There is no price on this model, and no accessor that returns one. Prices are
 * resolved by CartService from the catalog on every read — see the migration
 * for the full reasoning. Anything here that looked like a price would be a
 * second source of truth, and the second one is always the wrong one.
 *
 * @property int $id
 * @property int $cart_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $quantity
 * @property array<string, mixed>|null $options
 */
class CartItem extends Model
{
    /** @use HasFactory<\Database\Factories\CartItemFactory> */
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'options',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'options' => 'array',
        ];
    }

    /**
     * Keep `variant_key` in step with `product_variant_id`.
     *
     * The unique index spans `variant_key` rather than `product_variant_id`
     * because every SQL engine treats NULLs as distinct in a unique index —
     * which would exempt simple products from the one-line-per-product rule
     * entirely. Maintained in a model hook rather than by the service so a
     * factory, a seeder, or a console command cannot write a row that escapes
     * the constraint.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->variant_key = $item->product_variant_id ?? 0;
        });
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * The row that actually owns this line's price and stock.
     *
     * A variant, when one was chosen; the product otherwise. Every pricing and
     * availability decision reads through here so the variant-versus-product
     * question is answered in exactly one place.
     */
    public function stockable(): Product|ProductVariant|null
    {
        if ($this->product_variant_id !== null) {
            return $this->relationLoaded('variant') ? $this->variant : null;
        }

        return $this->relationLoaded('product') ? $this->product : null;
    }
}
