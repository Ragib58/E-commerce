<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces JSON content negotiation on API routes.
 *
 * Without this, a client that omits `Accept: application/json` (a browser
 * address bar, a misconfigured HTTP client) receives Laravel's HTML error page
 * instead of the error envelope. Rewriting the header at the edge of the API
 * group makes the contract unconditional.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
