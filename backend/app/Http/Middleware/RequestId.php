<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation id to every request and echoes it back on the response.
 *
 * An upstream proxy or the frontend may supply `X-Request-Id`; we honour it so
 * a single id spans the whole call chain. The id is pushed into the log context
 * so every line written during this request can be traced back to it.
 */
final class RequestId
{
    private const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header(self::HEADER);

        // Never trust an unbounded client-supplied value into the log context.
        if (! is_string($requestId) || $requestId === '' || strlen($requestId) > 64) {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $requestId);
        $request->headers->set(self::HEADER, $requestId);

        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
