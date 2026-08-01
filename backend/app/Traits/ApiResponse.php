<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Produces the single response envelope used by every API endpoint.
 *
 * Envelope contract:
 *   success: { "success": true,  "message": string, "data": mixed, "meta"?: object }
 *   failure: { "success": false, "message": string, "errors"?: object }
 *
 * Consumers can therefore branch on `success` alone and never need to inspect
 * the HTTP status to know whether a payload is present.
 */
trait ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    protected function successResponse(
        mixed $data = null,
        string $message = 'Request completed successfully.',
        int $status = Response::HTTP_OK,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        // Paginators and resource collections carry their own pagination
        // metadata; hoist it into `meta` so `data` stays a clean array.
        if ($data instanceof ResourceCollection || $data instanceof AbstractPaginator) {
            $resolved = $this->resolveResource($data);

            $payload['data'] = $resolved['data'];
            $meta = array_merge($resolved['meta'], $meta);
        } elseif ($data instanceof JsonResource) {
            $resolved = $this->resolveResource($data);
            $payload['data'] = $resolved['data'];
        } else {
            $payload['data'] = $data;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status, $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    protected function errorResponse(
        string $message = 'The request could not be completed.',
        int $status = Response::HTTP_BAD_REQUEST,
        array $errors = [],
        ?string $code = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function createdResponse(mixed $data = null, string $message = 'Resource created successfully.'): JsonResponse
    {
        return $this->successResponse($data, $message, Response::HTTP_CREATED);
    }

    protected function noContentResponse(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Normalise a resource/paginator into its `data` body and `meta` block.
     *
     * @return array{data: mixed, meta: array<string, mixed>}
     */
    private function resolveResource(JsonResource|AbstractPaginator $resource): array
    {
        $response = $resource->toResponse(request());
        $decoded = json_decode($response->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);

        return [
            'data' => $decoded['data'] ?? $decoded,
            'meta' => $this->extractPaginationMeta($decoded),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function extractPaginationMeta(array $decoded): array
    {
        $meta = $decoded['meta'] ?? [];

        if ($meta === []) {
            return [];
        }

        return [
            'pagination' => [
                'current_page' => $meta['current_page'] ?? 1,
                'last_page' => $meta['last_page'] ?? 1,
                'per_page' => $meta['per_page'] ?? 0,
                'total' => $meta['total'] ?? 0,
                'from' => $meta['from'] ?? null,
                'to' => $meta['to'] ?? null,
            ],
        ];
    }
}
