<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PublishStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCmsPageRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCmsPageRequest;
use App\Http\Resources\Api\V1\CmsPageResource;
use App\Models\CmsPage;
use App\Services\CmsPageService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CMS page administration.
 */
final class CmsPageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CmsPageService $pages,
    ) {
    }

    /**
     * GET /admin/pages
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CmsPage::class);

        $pages = $this->pages->all([
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ]);

        return $this->successResponse(
            data: CmsPageResource::collection($pages),
            message: 'Pages retrieved successfully.',
            meta: [
                'statuses' => array_map(
                    static fn (PublishStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ],
                    PublishStatus::cases(),
                ),
            ],
        );
    }

    /**
     * GET /admin/pages/{page}
     *
     * Bound by slug, matching the storefront URL — so an operator who has a
     * page's public address can reach its editor without looking up an id.
     */
    public function show(CmsPage $page): JsonResponse
    {
        $this->authorize('view', $page);

        return $this->successResponse(
            data: new CmsPageResource($page),
            message: 'Page retrieved successfully.',
        );
    }

    /**
     * POST /admin/pages
     */
    public function store(StoreCmsPageRequest $request): JsonResponse
    {
        $page = $this->pages->create($request->payload());

        return $this->createdResponse(
            data: new CmsPageResource($page),
            message: 'Page created successfully.',
        );
    }

    /**
     * PATCH /admin/pages/{page}
     */
    public function update(UpdateCmsPageRequest $request, CmsPage $page): JsonResponse
    {
        $updated = $this->pages->update($page, $request->payload());

        return $this->successResponse(
            data: new CmsPageResource($updated),
            message: 'Page updated successfully.',
        );
    }

    /**
     * DELETE /admin/pages/{page}
     *
     * Refused for the seeded system pages.
     *
     * Permission is checked with the `create` ability rather than `delete`,
     * deliberately: both require `manage_content`, but `delete` additionally
     * refuses system pages, and reaching it here would answer "you may not do
     * that" when the truthful answer is "this page cannot be deleted at all,
     * unpublish it instead". The service raises that message as a validation
     * error; the policy still guards any other caller.
     */
    public function destroy(CmsPage $page): JsonResponse
    {
        $this->authorize('create', CmsPage::class);

        $this->pages->delete($page);

        return $this->successResponse(message: 'Page deleted successfully.');
    }

    /**
     * PATCH /admin/pages/{page}/status
     */
    public function setStatus(Request $request, CmsPage $page): JsonResponse
    {
        $this->authorize('update', $page);

        $validated = $request->validate([
            'status' => ['required', \Illuminate\Validation\Rule::enum(PublishStatus::class)],
        ]);

        $updated = $this->pages->update($page, ['status' => $validated['status']]);

        return $this->successResponse(
            data: new CmsPageResource($updated),
            message: 'Page status updated successfully.',
        );
    }
}
