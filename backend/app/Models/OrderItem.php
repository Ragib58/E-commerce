<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Casts\Attribute as CastAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an order.
 *
 * Unlike {@see CartItem}, this model *does* carry prices — and that inversion
 * is the point. A cart line must track the catalog because it is an intention;
 * an order line must not, because it is a record of what was agreed. Every
 * displayable field is copied here at placement so an invoice renders correctly
 * years later, after the product has been renamed, restructured, or archived.
 *
 * The `product` and `variant` relations exist for reporting, not for display.
 * Nothing that renders an invoice or a packing slip should read through them:
 * they resolve to the *current* catalog, which is precisely what the snapshot
 * exists to avoid.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property string $product_name
 * @property string|null $product_sku
 * @property string|null $variant_name
 * @property string|null $product_type
 * @property array<string, string>|null $variant_options
 * @property array<string, mixed>|null $options
 * @property string|null $thumbnail_url
 * @property int $quantity
 * @property int $unit_price
 * @property int|null $list_price
 * @property int $discount_total
 * @property int $tax_total
 * @property int $line_total
 * @property bool $is_taxable
 * @property bool $stock_was_reduced
 * @property int $refunded_quantity
 * @property-read int $refundable_quantity
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'product_sku',
        'variant_name',
        'product_type',
        'variant_options',
        'options',
        'thumbnail_url',
        'quantity',
        'unit_price',
        'list_price',
        'discount_total',
        'tax_total',
        'line_total',
        'is_taxable',
        'stock_was_reduced',
        'refunded_quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variant_options' => 'array',
            'options' => 'array',
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'list_price' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'line_total' => 'integer',
            'is_taxable' => 'boolean',
            'stock_was_reduced' => 'boolean',
            'refunded_quantity' => 'integer',
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
     * The catalog row, for reporting only. See the class docblock.
     *
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
     * How many units of this line may still be refunded or returned.
     *
     * @return CastAttribute<int, never>
     */
    protected function refundableQuantity(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): int => max(0, (int) $this->quantity - (int) $this->refunded_quantity),
        );
    }

    /**
     * The full name as it should appear on an invoice.
     *
     * Product and variant joined, from the snapshot columns — never from the
     * live catalog.
     */
    public function displayName(): string
    {
        if ($this->variant_name === null || $this->variant_name === '') {
            return $this->product_name;
        }

        return $this->product_name.' — '.$this->variant_name;
    }

    /**
     * The stockable this line drew from, for restocking.
     *
     * Returns null when the catalog row has since been deleted: a refund of a
     * line whose product no longer exists still refunds the money, it just has
     * nowhere to put the units back. Silently doing nothing is correct here;
     * failing the refund because the catalog moved on would not be.
     */
    public function stockable(): Product|ProductVariant|null
    {
        if ($this->product_variant_id !== null) {
            return ProductVariant::query()->with('product')->find($this->product_variant_id);
        }

        if ($this->product_id !== null) {
            return Product::query()->find($this->product_id);
        }

        return null;
    }

    /**
     * Whether the stored components add up to the stored line total.
     */
    public function totalsReconcile(): bool
    {
        return (int) $this->unit_price * (int) $this->quantity === (int) $this->line_total;
    }
}
