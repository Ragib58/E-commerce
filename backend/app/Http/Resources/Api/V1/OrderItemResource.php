<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line on an order.
 *
 * Everything here is read from the line's own snapshot columns, never through
 * the `product` relation. A product renamed or archived since placement must
 * not change what an order says was bought — see the OrderItem model.
 *
 * `product_id` is emitted as the catalog uuid where the product still exists,
 * so the storefront can link "buy it again". Null when the product is gone,
 * which the client renders as an un-linked line rather than a broken link.
 *
 * @mixin OrderItem
 */
final class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            /*
             * The snapshot. These are what the order actually says.
             */
            'name' => $this->product_name,
            'sku' => $this->product_sku,
            'variant_name' => $this->variant_name,
            'variant_options' => $this->variant_options,
            'options' => $this->options,
            'thumbnail' => $this->thumbnail_url,
            'display_name' => $this->displayName(),

            'quantity' => $this->quantity,

            /*
             * Money as integer minor units, matching the rest of the API. A
             * pre-formatted string could not be re-formatted for another locale
             * and could not be summed — presentation belongs to the client.
             */
            'unit_price' => $this->unit_price,
            'list_price' => $this->list_price,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'line_total' => $this->line_total,

            'is_taxable' => $this->is_taxable,

            'refunded_quantity' => $this->refunded_quantity,
            'refundable_quantity' => $this->refundable_quantity,

            /*
             * The live catalog link, for a re-order button. Resolved from the
             * relation only when it was eager-loaded, so a list view does not
             * fire a query per line.
             */
            'product' => $this->whenLoaded('product', fn (): ?array => $this->product === null ? null : [
                'id' => $this->product->uuid,
                'slug' => $this->product->slug,
                'is_available' => $this->product->status->isVisible(),
            ]),
        ];
    }
}
