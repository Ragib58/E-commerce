<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddressType;
use Database\Factories\OrderAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shipping or billing address captured on an order.
 *
 * A snapshot, like the order's prices and product names. See the migration for
 * why there is no relation to a saved address book.
 *
 * @property int $id
 * @property int $order_id
 * @property AddressType $type
 * @property string $first_name
 * @property string $last_name
 * @property string|null $company
 * @property string|null $phone
 * @property string|null $email
 * @property string $line1
 * @property string|null $line2
 * @property string $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string $country
 * @property string|null $delivery_instructions
 */
class OrderAddress extends Model
{
    /** @use HasFactory<OrderAddressFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'type',
        'first_name',
        'last_name',
        'company',
        'phone',
        'email',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'country',
        'delivery_instructions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * The address as an ordered list of lines, ready to print.
     *
     * Empty parts are dropped rather than rendered as blank lines: an address
     * with no county should not print a gap where one would be, and a label
     * with a hole in it looks like a rendering bug to whoever receives it.
     *
     * @return array<int, string>
     */
    public function lines(): array
    {
        $lines = [
            $this->fullName(),
            $this->company,
            $this->line1,
            $this->line2,
            // City and postcode share a line, as every postal service expects.
            trim(implode(' ', array_filter([$this->city, $this->postal_code]))),
            $this->state,
            $this->country,
        ];

        return array_values(array_filter(
            array_map(static fn (?string $line): string => trim((string) $line), $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * The address on one line, for a table cell or a search result.
     */
    public function inline(): string
    {
        return implode(', ', $this->lines());
    }

    /**
     * Whether two addresses describe the same place.
     *
     * Used to decide whether an invoice needs to print both a billing and a
     * shipping block, or one. Compares the postal fields only — a different
     * recipient name at the same address is still the same address for that
     * purpose, and printing the block twice because a middle name was added
     * wastes half a page.
     */
    public function matches(?self $other): bool
    {
        if ($other === null) {
            return false;
        }

        foreach (['line1', 'line2', 'city', 'state', 'postal_code', 'country'] as $field) {
            if (strcasecmp(trim((string) $this->{$field}), trim((string) $other->{$field})) !== 0) {
                return false;
            }
        }

        return true;
    }
}
