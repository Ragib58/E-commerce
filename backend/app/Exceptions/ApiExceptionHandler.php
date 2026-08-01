<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps every throwable raised on an API route onto the shared error envelope.
 *
 * Registered from bootstrap/app.php. Web and admin routes keep Laravel's
 * default HTML error pages — only requests that expect JSON are intercepted.
 */
final class ApiExceptionHandler
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! self::expectsJson($request)) {
                return null;
            }

            // HttpResponseException carries a fully-built response rather than
            // describing an error. Rate limiters and any `abort_if` with a
            // custom response arrive this way, and re-serialising them would
            // discard the intended status — a 429 with our envelope would be
            // rewritten as a generic 500.
            if ($e instanceof HttpResponseException) {
                $response = $e->getResponse();

                return $response instanceof JsonResponse
                    ? $response
                    : self::toJsonResponse($e, $request);
            }

            return self::toJsonResponse($e, $request);
        });

        // Errors are already serialised into the envelope; skip the default
        // HTML report path for API requests.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => self::expectsJson($request)
        );
    }

    private static function expectsJson(Request $request): bool
    {
        return $request->is('api/*')
            || $request->expectsJson()
            || $request->wantsJson();
    }

    private static function toJsonResponse(Throwable $e, Request $request): JsonResponse
    {
        [$status, $message, $errors, $code] = match (true) {
            $e instanceof ValidationException => [
                422,
                'The given data was invalid.',
                $e->errors(),
                'VALIDATION_FAILED',
            ],
            $e instanceof AuthenticationException => [
                401,
                'Unauthenticated.',
                [],
                'UNAUTHENTICATED',
            ],
            $e instanceof AuthorizationException => [
                403,
                $e->getMessage() !== '' ? $e->getMessage() : 'This action is unauthorized.',
                [],
                'FORBIDDEN',
            ],
            $e instanceof ModelNotFoundException => [
                404,
                'The requested resource was not found.',
                [],
                'RESOURCE_NOT_FOUND',
            ],
            $e instanceof NotFoundHttpException => [
                404,
                'The requested endpoint was not found.',
                [],
                'ENDPOINT_NOT_FOUND',
            ],
            $e instanceof MethodNotAllowedHttpException => [
                405,
                'The HTTP method is not supported for this endpoint.',
                [],
                'METHOD_NOT_ALLOWED',
            ],
            $e instanceof ThrottleRequestsException,
            $e instanceof TooManyRequestsHttpException => [
                429,
                'Too many requests. Please slow down.',
                [],
                'RATE_LIMITED',
            ],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                [],
                'HTTP_ERROR',
            ],
            default => [
                500,
                config('app.debug') ? $e->getMessage() : 'An unexpected server error occurred.',
                [],
                'SERVER_ERROR',
            ],
        };

        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        // Debug details are opt-in and never leak in production.
        if (config('app.debug') && $status >= 500) {
            $payload['debug'] = [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect($e->getTrace())
                    ->take(15)
                    ->map(fn (array $frame): string => sprintf(
                        '%s:%s %s%s%s()',
                        $frame['file'] ?? '[internal]',
                        $frame['line'] ?? '0',
                        $frame['class'] ?? '',
                        $frame['type'] ?? '',
                        $frame['function'] ?? '',
                    ))
                    ->all(),
            ];
        }

        $headers = [];

        if ($requestId = $request->attributes->get('request_id')) {
            $headers['X-Request-Id'] = (string) $requestId;
        }

        return response()->json(
            $payload,
            $status,
            $headers,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
