<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Throwable;

/**
 * ApiResponse
 *
 * A single, predictable JSON envelope for every endpoint, built for
 * both web and mobile clients:
 *
 * {
 *   "success": true,
 *   "message": "Success",
 *   "data": {...} | [...] | null,
 *   "errors": null,
 *   "meta": {
 *     "timestamp": "2026-07-12T09:41:00+00:00",
 *     "request_id": "b3c1e2b0-...-uuid",
 *     "version": "v1",
 *     "current_page": 1,      // present only when data is a paginator
 *     "per_page": 15,
 *     "total": 132,
 *     "last_page": 9,
 *     "from": 1,
 *     "to": 15,
 *     "has_more": true,
 *     "summary": { ... }      // present only when explicitly passed — shape is up to the caller
 *   }
 * }
 *
 * Design goals:
 *  - One shape for success AND error responses (clients never branch
 *    on structure, only on `success`).
 *  - Machine-readable `error_code` strings (stable across releases)
 *    in addition to human-readable `message`, so mobile apps can
 *    localize/switch on codes instead of parsing English text.
 *  - `request_id` on every response for support/debugging and log
 *    correlation across web + mobile + backend.
 *  - Automatic pagination meta when a paginator is passed as data.
 *  - Debug-only stack traces, never leaked in production.
 *  - Optional `meta.summary`: a free-form placeholder for whatever
 *    summary data an endpoint needs (dashboard totals, aggregates,
 *    counts, etc.) — no fixed shape enforced, pass an array/object as
 *    needed. Only present in `meta` when explicitly passed, same as
 *    pagination fields.
 */
class ApiResponse
{
    /** Default API version tag included in meta. Override via config('api.version'). */
    protected static function version(): string
    {
        return config('api.version', 'v1');
    }

    /** Unique ID for this response — reuse the current request ID if middleware set one. */
    protected static function requestId(): string
    {
        return request()->attributes->get('request_id')
            ?? request()->header('X-Request-Id')
            ?? (string) Str::uuid();
    }

    protected static function baseMeta(array $extra = []): array
    {
        return array_merge([
            'timestamp'  => now()->toIso8601String(),
            'request_id' => static::requestId(),
            'version'    => static::version(),
        ], $extra);
    }

    /**
     * Normalize data: unwrap Resources/Paginators and extract pagination meta
     * automatically, so controllers don't have to build it by hand.
     */
    protected static function normalizeData($data, array &$meta): mixed
    {
        // Handle ResourceCollections (which is what AdminUnitResource::collection returns)
        if ($data instanceof \Illuminate\Http\Resources\Json\ResourceCollection) {
            $paginator = $data->resource; // Extract the underlying Paginator

            if ($paginator instanceof LengthAwarePaginator) {
                $meta['current_page'] = $paginator->currentPage();
                $meta['per_page']     = $paginator->perPage();
                $meta['total']        = $paginator->total();
                $meta['last_page']    = $paginator->lastPage();
                $meta['from']         = $paginator->firstItem();
                $meta['to']           = $paginator->lastItem();
                $meta['has_more']     = $paginator->hasMorePages();
                
                return $data; // Return the collection, the meta is now populated by reference
            }
        }

        if ($data instanceof JsonResource) {
            return $data->resolve(request());
        }

        return $data;
    }

    /**
     * Shared envelope builder used by every response type.
     */
    protected static function respond(
        bool $success,
        string $message,
        mixed $data = null,
        mixed $errors = null,
        int $status = 200,
        array $meta = [],
        ?string $errorCode = null,
        mixed $summary = null
    ): JsonResponse {
        $data = static::normalizeData($data, $meta);

        if ($summary !== null) {
            $meta['summary'] = $summary;
        }

        $payload = [
            'success' => $success,
            'message' => $message,
            'data'    => $data,
            'errors'  => $errors,
            'meta'    => static::baseMeta($meta),
        ];

        if ($errorCode !== null) {
            $payload['error_code'] = $errorCode;
        }

        return response()->json($payload, $status)
            ->header('X-Request-Id', static::requestId());
    }

    /* -----------------------------------------------------------------
     |  2xx — success responses
     | -----------------------------------------------------------------
     */

    public static function success(mixed $data = null, string $message = 'Success', array $meta = [], int $status = 200, mixed $summary = null): JsonResponse
    {
        return static::respond(true, $message, $data, null, $status, $meta, null, $summary);
    }

    public static function created(mixed $data = null, string $message = 'Resource created successfully', array $meta = [], ?string $summary = null): JsonResponse
    {
        return static::respond(true, $message, $data, null, 201, $meta, null, $summary);
    }

    public static function updated(mixed $data = null, string $message = 'Resource updated successfully', array $meta = [], ?string $summary = null): JsonResponse
    {
        return static::respond(true, $message, $data, null, 200, $meta, null, $summary);
    }

    /** 204 has no body per RFC 7231 — returns an empty response, not the JSON envelope. */
    public static function deleted(string $message = 'Resource deleted successfully'): JsonResponse
    {
        return response()->json(null, 204);
    }

    /* -----------------------------------------------------------------
     |  4xx / 5xx — error responses
     | -----------------------------------------------------------------
     */

    public static function error(
        string $message = 'An error occurred',
        mixed $errors = null,
        int $status = 400,
        string $errorCode = 'ERROR',
        ?string $summary = null
    ): JsonResponse {
        return static::respond(false, $message, null, $errors, $status, [], $errorCode, $summary);
    }

    public static function validation(mixed $errors, string $message = 'The given data was invalid'): JsonResponse
    {
        return static::error($message, $errors, 422, 'VALIDATION_ERROR');
    }

    public static function unauthorized(string $message = 'Authentication required'): JsonResponse
    {
        return static::error($message, null, 401, 'UNAUTHORIZED');
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action'): JsonResponse
    {
        return static::error($message, null, 403, 'FORBIDDEN');
    }

    public static function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return static::error($message, null, 404, 'NOT_FOUND');
    }

    public static function conflict(string $message = 'Resource conflict', mixed $errors = null): JsonResponse
    {
        return static::error($message, $errors, 409, 'CONFLICT');
    }

    public static function tooManyRequests(string $message = 'Too many requests, please slow down', ?int $retryAfterSeconds = null): JsonResponse
    {
        $response = static::error($message, null, 429, 'RATE_LIMITED');

        if ($retryAfterSeconds !== null) {
            $response->header('Retry-After', (string) $retryAfterSeconds);
        }

        return $response;
    }

    /**
     * 500 — never leaks internal details in production; includes
     * exception info + trace only when app.debug is true.
     */
    public static function serverError(
        string $message = 'Something went wrong on our end. Please try again later.',
        ?Throwable $exception = null
    ): JsonResponse {
        $errors = null;

        if ($exception && config('app.debug')) {
            $errors = [
                'exception' => get_class($exception),
                'message'   => $exception->getMessage(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => collect($exception->getTrace())->take(10)->toArray(),
            ];
        }

        return static::error($message, $errors, 500, 'SERVER_ERROR');
    }
}