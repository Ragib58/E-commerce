<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrderNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A note on an order's thread.
 *
 * This resource does **not** decide visibility. OrderResource picks the
 * relation — `notes` for staff, `customerVisibleNotes` for the shopper — so the
 * filter runs in SQL and an internal note is never loaded into a customer's
 * payload at all. Filtering here instead would leave it in memory, one
 * forgotten line away from being serialised.
 *
 * @mixin OrderNote
 */
final class OrderNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,

            // The denormalised label, so it still resolves after the account
            // that wrote it has been deleted.
            'author' => $this->authorName(),
            'is_from_customer' => $this->isFromCustomer(),

            'is_customer_visible' => $this->is_customer_visible,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
