<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SectionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreHomepageSectionRequest;
use App\Http\Requests\Api\V1\Admin\UpdateHomepageSectionRequest;
use App\Http\Resources\Api\V1\HomepageSectionResource;
use App\Models\HomepageSection;
use App\Services\HomepageService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The homepage builder.
 *
 * Unlike the public surface this returns *every* section — disabled, scheduled,
 * and expired alike — because an operator cannot edit what the panel will not
 * show them, and a section that has silently expired is exactly the one they
 * need to find.
 */
final class HomepageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly HomepageService $homepage,
    ) {
    }

    /**
     * GET /admin/homepage/sections
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', HomepageSection::class);

        return $this->successResponse(
            data: HomepageSectionResource::collection($this->homepage->all()),
            message: 'Homepage sections retrieved successfully.',
            meta: [
                /*
                 * The section-type catalogue, served alongside the sections.
                 *
                 * The panel's "add section" menu, its per-type form controls,
                 * and its default settings all come from here rather than from
                 * a hardcoded list in the frontend — so a new section type is a
                 * backend change alone.
                 */
                'available_types' => SectionType::catalogue(),
            ],
        );
    }

    /**
     * GET /admin/homepage/preview
     *
     * The homepage exactly as the storefront would receive it, optionally at a
     * chosen moment.
     *
     * `?at=` is what makes scheduling reviewable: an operator can confirm that
     * a Black Friday section appears on the day without waiting for the day, or
     * that a campaign really does disappear when it expires. Uncached, and
     * resolved live — a preview served from the cache would answer a question
     * nobody asked.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HomepageSection::class);

        $validated = $request->validate([
            'at' => ['sometimes', 'date'],
        ]);

        $at = isset($validated['at']) ? Carbon::parse((string) $validated['at']) : Carbon::now();

        $sections = $this->homepage->render($at);

        return $this->successResponse(
            data: $sections->all(),
            message: 'Homepage preview generated successfully.',
            meta: [
                'previewed_at' => $at->toIso8601String(),
                'section_count' => $sections->count(),
            ],
        );
    }

    /**
     * POST /admin/homepage/sections
     */
    public function store(StoreHomepageSectionRequest $request): JsonResponse
    {
        $section = $this->homepage->create($request->payload());

        return $this->createdResponse(
            data: new HomepageSectionResource($section),
            message: 'Section added successfully.',
        );
    }

    /**
     * GET /admin/homepage/sections/{section}
     */
    public function show(HomepageSection $section): JsonResponse
    {
        $this->authorize('view', $section);

        return $this->successResponse(
            data: new HomepageSectionResource($section),
            message: 'Section retrieved successfully.',
        );
    }

    /**
     * PATCH /admin/homepage/sections/{section}
     */
    public function update(UpdateHomepageSectionRequest $request, HomepageSection $section): JsonResponse
    {
        $updated = $this->homepage->update($section, $request->payload());

        return $this->successResponse(
            data: new HomepageSectionResource($updated),
            message: 'Section updated successfully.',
        );
    }

    /**
     * DELETE /admin/homepage/sections/{section}
     */
    public function destroy(HomepageSection $section): JsonResponse
    {
        $this->authorize('delete', $section);

        $this->homepage->delete($section);

        return $this->successResponse(message: 'Section removed successfully.');
    }

    /**
     * PATCH /admin/homepage/sections/{section}/status
     *
     * Enable or disable one section.
     *
     * Separate from the general update so the builder's toggle is one request
     * that cannot accidentally carry — and overwrite — the rest of the form.
     */
    public function setEnabled(Request $request, HomepageSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);

        $updated = $this->homepage->setEnabled($section, (bool) $validated['is_enabled']);

        return $this->successResponse(
            data: new HomepageSectionResource($updated),
            message: $updated->is_enabled
                ? 'Section enabled successfully.'
                : 'Section disabled successfully.',
        );
    }

    /**
     * PUT /admin/homepage/sections/reorder
     *
     * Persist a drag-and-drop rearrangement in one request.
     *
     * Sending each moved section separately would leave the page in an order
     * nobody chose if one call failed partway through — and reordering moves
     * every section below the drop point, so that is the common case rather
     * than an edge one.
     */
    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('reorder', HomepageSection::class);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:homepage_sections,id'],
            'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        $this->homepage->reorder($validated['items']);

        return $this->successResponse(
            data: HomepageSectionResource::collection($this->homepage->all()),
            message: 'Sections reordered successfully.',
        );
    }
}
