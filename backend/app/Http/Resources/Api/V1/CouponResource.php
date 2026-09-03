<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A coupon, for the admin panel.
 *
 * There is no customer-facing coupon resource. A shopper never browses coupon
 * *records* — they type a code and read back a discount, which is
 * CouponService::preview()'s plain array, not a model serialisation. Exposing
 * this resource publicly would also leak `used_count`, `usage_limit`, and
 * `created_by`, none of which is any shopper's business.
 *
 * @mixin Coupon
 */
final class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,

            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'value' => $this->value,
            'max_discount' => $this->max_discount,
            'min_order_amount' => $this->min_order_amount,
            'free_shipping' => $this->free_shipping,

            'applies_to_all' => $this->applies_to_all,
            'products' => $this->whenLoaded('products', fn (): array => $this->products
                ->map(fn ($product): array => [
                    'id' => $product->uuid,
                    'name' => $product->name,
                    'excluded' => (bool) $product->pivot->is_excluded,
                ])
                ->values()
                ->all()),
            'categories' => $this->whenLoaded('categories', fn (): array => $this->categories
                ->map(fn ($category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'excluded' => (bool) $category->pivot->is_excluded,
                    'includes_descendants' => (bool) $category->pivot->includes_descendants,
                ])
                ->values()
                ->all()),

            'first_order_only' => $this->first_order_only,

            'user_restricted' => $this->user_restricted,
            // Only the count in a list view; the whole customer list is
            // fetched only on the coupon's own detail screen, where the
            // relation is deliberately eager-loaded.
            'restricted_user_count' => $this->whenCounted('users'),
            'users' => $this->whenLoaded('users', fn (): array => $this->users
                ->map(fn ($user): array => ['id' => $user->uuid, 'name' => $user->name, 'email' => $user->email])
                ->values()
                ->all()),

            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_within_window' => $this->isWithinWindow(),

            'usage_limit' => $this->usage_limit,
            'per_user_limit' => $this->per_user_limit,
            'used_count' => $this->used_count,
            'has_reached_usage_limit' => $this->hasReachedUsageLimit(),

            'is_active' => $this->is_active,
            'is_public' => $this->is_public,

            'created_by' => $this->whenLoaded('creator', fn (): ?array => $this->creator === null ? null : [
                'id' => $this->creator->uuid,
                'name' => $this->creator->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
