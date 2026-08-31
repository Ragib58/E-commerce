<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrderAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shipping or billing address on an order.
 *
 * Emits the structured fields *and* a pre-assembled `lines` array. The
 * structured fields are for a client that needs to edit or reason about an
 * address; `lines` is for one that only needs to display it, and having the
 * server assemble it means every surface — the account page, the admin panel,
 * an email — orders the parts the same way.
 *
 * @mixin OrderAddress
 */
final class OrderAddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,

            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'company' => $this->company,

            'phone' => $this->phone,
            'email' => $this->email,

            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,

            'delivery_instructions' => $this->delivery_instructions,

            // Ready to render, with empty parts already dropped.
            'lines' => $this->lines(),
            'inline' => $this->inline(),
        ];
    }
}
