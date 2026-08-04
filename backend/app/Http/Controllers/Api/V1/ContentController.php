<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\BannerPlacement;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BannerResource;
use App\Http\Resources\Api\V1\CmsPageResource;
use App\Services\BannerService;
use App\Services\CmsPageService;
use App\Services\HomepageService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public storefront content: the homepage, banners, and CMS pages.
 *
 * Unauthenticated and read-only. Everything here is constrained to *live*
 * records inside the services — published status AND an open scheduling window
 * — so no route parameter can surface a draft page or a campaign that has not
 * launched.
 *
 * The homepage endpoint returns sections with their content already resolved.
 * That is the point of the module: the storefront renders whatever it is given,
 * in the order it is given, and holds no opinion about what a homepage
 * contains.
 */
final class ContentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly HomepageService $homepage,
        private readonly BannerService $banners,
        private readonly CmsPageService $pages,
    ) {
    }

    /**
     * GET /homepage
     *
     * The whole homepage in one request: every live section, in display order,
     * with its products, categories, or banners already resolved.
     *
     * One request rather than one per section — a homepage with six rails would
     * otherwise cost six round trips before anything could render, and the
     * waterfall would be visible on every cold visit.
     */
    public function homepage(): JsonResponse
    {
        $sections = $this->homepage->cachedRender();

        return $this->successResponse(
            data: $sections->all(),
            message: 'Homepage retrieved successfully.',
            meta: [
                'section_count' => $sections->count(),
                /*
                 * Lets the storefront distinguish "no sections configured yet"
                 * from "the request failed", which look identical from an empty
                 * array and call for very different UI.
                 */
                'is_configured' => $sections->isNotEmpty(),
            ],
        );
    }

    /**
     * GET /banners
     *
     * Live banners, optionally filtered to one placement. Serves surfaces
     * outside the homepage — a category header, a checkout strip — which fetch
     * their own banners rather than receiving them in a page payload.
     */
    public function banners(Request $request): JsonResponse
    {
        $placement = $request->string('placement')->toString();

        if ($placement !== '' && BannerPlacement::tryFrom($placement) === null) {
            return $this->errorResponse(
                message: 'The requested banner placement does not exist.',
                status: 422,
                code: 'INVALID_PLACEMENT',
            );
        }

        $limit = $request->has('limit')
            ? min(max((int) $request->integer('limit'), 1), 24)
            : null;

        $banners = $placement !== ''
            ? $this->banners->liveForPlacement($placement, $limit)
            : $this->banners->liveForPlacements(BannerPlacement::cases());

        return $this->successResponse(
            data: BannerResource::collection($banners),
            message: 'Banners retrieved successfully.',
        );
    }

    /**
     * GET /pages
     *
     * Published pages as titles and slugs, for footer navigation.
     *
     * Bodies are omitted deliberately: a footer needs six links, and sending
     * six full policy documents to render them would dominate the payload of
     * every page on the site.
     */
    public function pages(): JsonResponse
    {
        return $this->successResponse(
            data: CmsPageResource::collection($this->pages->publishedIndex()),
            message: 'Pages retrieved successfully.',
        );
    }

    /**
     * GET /pages/{slug}
     */
    public function page(string $slug): JsonResponse
    {
        $page = $this->pages->published($slug);

        if ($page === null) {
            // Indistinguishable from a slug that never existed. A different
            // response for "exists but unpublished" would let anyone enumerate
            // unreleased pages.
            return $this->errorResponse(
                message: 'The requested page could not be found.',
                status: 404,
                code: 'PAGE_NOT_FOUND',
            );
        }

        return $this->successResponse(
            data: new CmsPageResource($page),
            message: 'Page retrieved successfully.',
        );
    }
}
