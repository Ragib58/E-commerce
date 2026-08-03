<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementReason;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the append-only inventory ledger.
 *
 * Immutability is enforced here rather than merely documented: the update and
 * delete hooks below refuse outright. A history that can be quietly rewritten
 * cannot settle a stock dispute, so the guarantee has to hold even against a
 * careless `$movement->save()` somewhere in a future phase.
 *
 * Corrections are new rows, in the opposite direction.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property StockMovementType $type
 * @property StockMovementReason $reason
 * @property int $quantity
 * @property int $quantity_before
 * @property int $quantity_after
 * @property int|null $admin_id
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class StockMovement extends Model
{
    /** @use HasFactory<\Database\Factories\StockMovementFactory> */
    use HasFactory;

    /**
     * Rows are immutable, so there is no updated_at to maintain.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'type',
        'reason',
        'quantity',
        'quantity_before',
        'quantity_after',
        'admin_id',
        'note',
        'reference_type',
        'reference_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'reason' => StockMovementReason::class,
            'quantity' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Refuse any mutation of a recorded movement.
     *
     * Returning false from these hooks aborts the operation. This is the
     * mechanism that makes the ledger's append-only claim real rather than a
     * convention future code could break by accident.
     */
    protected static function booted(): void
    {
        static::updating(static fn (): bool => false);

        static::deleting(static fn (): bool => false);
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
     * The staff member who recorded it. Null for system-generated movements.
     *
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * The document that caused the movement — an order, a return, a purchase
     * order. Null for manual adjustments.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this movement targets a variant rather than the product itself.
     */
    public function isVariantMovement(): bool
    {
        return $this->product_variant_id !== null;
    }

    /**
     * @param  Builder<StockMovement>  $query
     * @return Builder<StockMovement>
     */
    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $inner) => $inner->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $inner) => $inner->where('created_at', '<=', $to));
    }

    /**
     * @param  Builder<StockMovement>  $query
     * @return Builder<StockMovement>
     */
    public function scopeShrinkage(Builder $query): Builder
    {
        $reasons = array_values(array_map(
            static fn (StockMovementReason $reason): string => $reason->value,
            array_filter(
                StockMovementReason::cases(),
                static fn (StockMovementReason $reason): bool => $reason->isShrinkage(),
            ),
        ));

        return $query->whereIn('reason', $reasons);
    }
}
