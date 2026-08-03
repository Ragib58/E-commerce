<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttributeResource;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Variant attribute administration — Size, Colour, Material.
 *
 * This is the surface that makes variants dynamic: an operator adds "Material"
 * with values "cotton" and "linen" and can immediately build variants from it,
 * with no migration and no deploy.
 *
 * Simple enough to handle its own persistence: there are no cross-record
 * invariants beyond per-attribute slug uniqueness, so a service class would add
 * indirection without adding a rule.
 */
final class AttributeController extends Controller
{
    use ApiResponse;

    /**
     * GET /admin/attributes
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        return $this->successResponse(
            data: AttributeResource::collection(
                Attribute::query()->with('values')->orderBy('sort_order')->orderBy('name')->get(),
            ),
            message: 'Attributes retrieved successfully.',
        );
    }

    /**
     * POST /admin/attributes
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'display_type' => ['sometimes', Rule::in(Attribute::DISPLAY_TYPES)],
            'is_filterable' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],

            // Values may be supplied inline so a whole attribute is created in
            // one request rather than one call per value.
            'values' => ['sometimes', 'array'],
            'values.*.value' => ['required', 'string', 'max:120'],
            'values.*.colour_code' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'values.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $attribute = DB::transaction(function () use ($validated): Attribute {
            $attribute = Attribute::query()->create([
                'name' => $validated['name'],
                'slug' => Attribute::generateSlug($validated['slug'] ?? $validated['name']),
                'display_type' => $validated['display_type'] ?? 'button',
                'is_filterable' => $validated['is_filterable'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            foreach ($validated['values'] ?? [] as $index => $value) {
                AttributeValue::query()->create([
                    'attribute_id' => $attribute->getKey(),
                    'value' => $value['value'],
                    'slug' => AttributeValue::generateSlug($value['value'], (int) $attribute->getKey()),
                    'colour_code' => $value['colour_code'] ?? null,
                    'sort_order' => $value['sort_order'] ?? $index,
                ]);
            }

            return $attribute;
        });

        return $this->createdResponse(
            data: new AttributeResource($attribute->load('values')),
            message: 'Attribute created successfully.',
        );
    }

    /**
     * PATCH /admin/attributes/{attribute}
     */
    public function update(Request $request, Attribute $attribute): JsonResponse
    {
        $this->authorize('update', Product::class);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'display_type' => ['sometimes', Rule::in(Attribute::DISPLAY_TYPES)],
            'is_filterable' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $attribute->fill($validated)->save();

        return $this->successResponse(
            data: new AttributeResource($attribute->refresh()->load('values')),
            message: 'Attribute updated successfully.',
        );
    }

    /**
     * DELETE /admin/attributes/{attribute}
     *
     * Refused while any variant is defined by one of its values — deleting it
     * would leave those variants unidentifiable.
     */
    public function destroy(Attribute $attribute): JsonResponse
    {
        $this->authorize('delete', Product::class);

        $inUse = AttributeValue::query()
            ->where('attribute_id', $attribute->getKey())
            ->whereHas('variants')
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'attribute' => ['This attribute defines existing product variants and cannot be deleted.'],
            ]);
        }

        $attribute->delete();

        return $this->successResponse(message: 'Attribute deleted successfully.');
    }

    /**
     * POST /admin/attributes/{attribute}/values
     */
    public function storeValue(Request $request, Attribute $attribute): JsonResponse
    {
        $this->authorize('update', Product::class);

        $validated = $request->validate([
            'value' => ['required', 'string', 'max:120'],
            'colour_code' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $value = AttributeValue::query()->create([
            'attribute_id' => $attribute->getKey(),
            'value' => $validated['value'],
            'slug' => AttributeValue::generateSlug($validated['value'], (int) $attribute->getKey()),
            'colour_code' => $validated['colour_code'] ?? null,
            'sort_order' => $validated['sort_order'] ?? (int) $attribute->values()->max('sort_order') + 1,
        ]);

        return $this->createdResponse(
            data: ['id' => $value->id, 'value' => $value->value, 'slug' => $value->slug],
            message: 'Attribute value added successfully.',
        );
    }

    /**
     * DELETE /admin/attributes/{attribute}/values/{value}
     */
    public function destroyValue(Attribute $attribute, AttributeValue $value): JsonResponse
    {
        $this->authorize('update', Product::class);

        if ((int) $value->attribute_id !== (int) $attribute->getKey()) {
            return $this->errorResponse(
                message: 'That value does not belong to this attribute.',
                status: 404,
            );
        }

        if ($value->variants()->exists()) {
            throw ValidationException::withMessages([
                'value' => ['This value defines existing product variants and cannot be deleted.'],
            ]);
        }

        $value->delete();

        return $this->successResponse(message: 'Attribute value deleted successfully.');
    }
}
