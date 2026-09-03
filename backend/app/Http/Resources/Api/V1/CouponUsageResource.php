<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CouponUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CouponUsage
 */
final class CouponUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'coupon_code' => $this->coupon_code,
            'discount_amount' => $this->discount_amount,
            'customer_email' => $this->customer_email,

            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->uuid,
                'name' => $this->user->name,
            ]),

            'order' => $this->whenLoaded('order', fn (): ?array => $this->order === null ? null : [
                'id' => $this->order->uuid,
                'order_number' => $this->order->order_number,
                'grand_total' => $this->order->grand_total,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
