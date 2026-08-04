<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BannerPlacement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBannerRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBannerRequest;
use App\Http\Resources\Api\V1\BannerResource;
use App\Models\Banner;
use App\Services\BannerService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Banner administration.
 *
 * Returns drafts, scheduled, and expired banners — the ones the storefront
 * hides are precisely the ones an operator has come here to find.
 */
final class BannerController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BannerService $banners,
    ) {
    }

    /**
     * GET /admin/banners
     *
     * Unpaginated by design: banners number in the dozens, they are managed as
     * ordered groups per placement, and a page boundary through a drag-and-drop
     * list makes reordering across it impossible.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Banner::class);

        $banners = $this->banners->all([
            'placement' => $request->string('placement')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ]);

        return $this->successResponse(
            data: BannerResource::collection($banners),
            message: 'Banners retrieved successfully.',
            meta: [
                // Drives the placement picker, so adding a placement is a
                // backend change alone.
                'placements' => BannerPlacement::options(),
            ],
        );
    }

    /**
     * GET /admin/banners/{banner}
     */
    public function show(Banner $banner): JsonResponse
    {
        $this->authorize('view', $banner);

        return $this->successResponse(
            data: new BannerResource($banner),
            message: 'Banner retrieved successfully.',
        );
    }

    /**
     * POST /admin/banners
     *
     * Multipart: the image is uploaded with the banner rather than in a
     * separate step, so a failed upload cannot leave a banner with no artwork.
     */
    public function store(StoreBannerRequest $request): JsonResponse
    {
        $banner = $this->banners->create($request->payload());

        return $this->createdResponse(
            data: new BannerResource($banner),
            message: 'Banner created successfully.',
        );
    }

    /**
     * PATCH /admin/banners/{banner}
     */
    public function update(UpdateBannerRequest $request, Banner $banner): JsonResponse
    {
        $updated = $this->banners->update($banner, $request->payload());

        return $this->successResponse(
            data: new BannerResource($updated),
            message: 'Banner updated successfully.',
        );
    }

    /**
     * DELETE /admin/banners/{banner}
     */
    public function destroy(Banner $banner): JsonResponse
    {
        $this->authorize('delete', $banner);

        $this->banners->delete($banner);

        return $this->successResponse(message: 'Banner deleted successfully.');
    }

    /**
     * PUT /admin/banners/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('reorder', Banner::class);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:banners,id'],
            'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        $this->banners->reorder($validated['items']);

        return $this->successResponse(message: 'Banners reordered successfully.');
    }
}
