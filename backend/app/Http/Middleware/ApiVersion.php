<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the API version from the URI segment and stamps it on the response.
 *
 * Versioning is URI-based (/api/v1/...). This middleware does not route — it
 * makes the active version introspectable inside the request lifecycle
 * (`$request->attributes->get('api_version')`) and visible to clients, so a
 * consumer can assert which contract answered without parsing the URL.
 */
final class ApiVersion
{
    private const HEADER = 'X-API-Version';

    public function handle(Request $request, Closure $next, ?string $version = null): Response
    {
        $resolved = $version
            ?? $this->versionFromPath($request)
            ?? (string) config('api.default_version', 'v1');

        $request->attributes->set('api_version', $resolved);

        $response = $next($request);
        $response->headers->set(self::HEADER, $resolved);
        $response->headers->set('X-API-Supported-Versions', implode(',', (array) config('api.supported_versions', ['v1'])));

        return $response;
    }

    private function versionFromPath(Request $request): ?string
    {
        // Path shape: api/{version}/{...}
        $segment = $request->segment(2);

        if (is_string($segment) && preg_match('/^v\d+$/', $segment) === 1) {
            return $segment;
        }

        return null;
    }
}
