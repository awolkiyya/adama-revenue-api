<?php

namespace App\Services;

use App\Models\SystemLog;
use App\Models\User;
use App\Supports\SystemLogAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SystemLogService
{
    /**
     * ============================================================
     * SENSITIVE FIELDS
     * ============================================================
     *
     * These fields must NEVER be stored in audit logs.
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',

        'token',
        'access_token',
        'refresh_token',

        'api_token',
        'remember_token',

        'authorization',
        'cookie',
        'set-cookie',

        'secret',
        'client_secret',

        'private_key',
        'secret_key',
    ];

    /**
     * ============================================================
     * RECORD GENERIC SYSTEM EVENT
     * ============================================================
     */
    public function log(
        string $action,
        ?string $module = null,
        ?Model $resource = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?User $actor = null,
    ): ?SystemLog {
        try {
            $request = request();

            /*
            |--------------------------------------------------------------------------
            | Resolve actor
            |--------------------------------------------------------------------------
            |
            | Your API uses Sanctum authentication.
            |
            | First try the Sanctum guard explicitly.
            | Then fall back to the default authenticated user.
            |
            */

            $actor ??= Auth::guard('sanctum')->user();

            if (!$actor) {
                $actor = Auth::user();
            }

            /*
            |--------------------------------------------------------------------------
            | Resource information
            |--------------------------------------------------------------------------
            */

            $resourceType = null;
            $resourceId = null;

            if ($resource instanceof Model) {
                $resourceType = $resource::class;

                /*
                 * UUID-safe.
                 *
                 * getKey() returns the actual primary key regardless
                 * of whether the model uses UUID or integer IDs.
                 */
                $resourceId = $resource->getKey();
            }

            /*
            |--------------------------------------------------------------------------
            | Request ID
            |--------------------------------------------------------------------------
            |
            | Prefer the request header, then the request attribute.
            |
            */

            $requestId =
                $request?->header('X-Request-ID')
                ?? $request?->attributes->get('request_id');

            /*
            |--------------------------------------------------------------------------
            | Build audit record
            |--------------------------------------------------------------------------
            */

            $data = [
                /*
                 * users.id is UUID.
                 *
                 * getKey() therefore returns the UUID string.
                 */
                'user_id' => $actor?->getKey(),

                'action' => strtoupper($action),

                'module' => $module,

                'resource_type' => $resourceType,

                /*
                 * resource_id is VARCHAR in system_logs,
                 * therefore always store it as a string.
                 */
                'resource_id' =>
                    $resourceId !== null
                        ? (string) $resourceId
                        : null,

                'description' => $description,

                /*
                 * Sanitize before persistence.
                 */
                'old_values' =>
                    $this->sanitize($oldValues),

                'new_values' =>
                    $this->sanitize($newValues),

                /*
                 * Request IDs are UUID strings.
                 */
                'request_id' =>
                    $requestId
                    ? (string) $requestId
                    : null,

                'ip_address' =>
                    $request?->ip(),

                'user_agent' =>
                    $this->truncateUserAgent(
                        $request?->userAgent()
                    ),

                'metadata' =>
                    $this->sanitize($metadata),

                'created_at' =>
                    now(),
            ];

            /*
            |--------------------------------------------------------------------------
            | Persist
            |--------------------------------------------------------------------------
            |
            | SystemLog::create() uses the fillable fields defined
            | in the SystemLog model.
            |
            */

            return SystemLog::create($data);

        } catch (Throwable $exception) {

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            |
            | Audit logging must NEVER break the main business operation.
            |
            | If logging fails:
            |
            | - Record the logging failure
            | - Return null
            | - Do NOT throw the exception
            |
            */

            Log::error(
                'SystemLogService: failed to create audit log',
                [
                    'action' => $action,
                    'module' => $module,

                    'resource_type' =>
                        $resource?->getMorphClass(),

                    'resource_id' =>
                        $resource?->getKey(),

                    'exception' =>
                        get_class($exception),

                    'message' =>
                        $exception->getMessage(),
                ]
            );

            return null;
        }
    }

    /**
     * ============================================================
     * CREATE
     * ============================================================
     */
    public function created(
        Model $resource,
        string $module,
        ?string $description = null,
        ?array $newValues = null,
        ?array $metadata = null,
    ): ?SystemLog {

        $newValues ??=
            $resource->getAttributes();

        return $this->log(
            action: SystemLogAction::CREATE,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? class_basename($resource)
                . ' created successfully',
            newValues: $newValues,
            metadata: $metadata,
        );
    }

    /**
     * ============================================================
     * UPDATE
     * ============================================================
     */
    public function updated(
        Model $resource,
        string $module,
        array $oldValues,
        ?array $newValues = null,
        ?string $description = null,
        ?array $metadata = null,
    ): ?SystemLog {

        $newValues ??=
            $resource->getAttributes();

        return $this->log(
            action: SystemLogAction::UPDATE,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? class_basename($resource)
                . ' updated successfully',
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
        );
    }

    /**
     * ============================================================
     * DELETE
     * ============================================================
     */
    public function deleted(
        Model $resource,
        string $module,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $metadata = null,
    ): ?SystemLog {

        $oldValues ??=
            $resource->getAttributes();

        return $this->log(
            action: SystemLogAction::DELETE,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? class_basename($resource)
                . ' deleted successfully',
            oldValues: $oldValues,
            metadata: $metadata,
        );
    }

    /**
     * ============================================================
     * VIEW
     * ============================================================
     */
    public function viewed(
        Model $resource,
        string $module,
        ?string $description = null,
        ?array $metadata = null,
    ): ?SystemLog {

        return $this->log(
            action: SystemLogAction::VIEW,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? class_basename($resource)
                . ' viewed',
            metadata: $metadata,
        );
    }

    /**
     * ============================================================
     * SECURITY EVENT
     * ============================================================
     */
    public function security(
        string $action,
        ?string $description = null,
        ?array $metadata = null,
        ?User $actor = null,
    ): ?SystemLog {

        return $this->log(
            action: $action,
            module: 'Authentication',
            description: $description,
            metadata: $metadata,
            actor: $actor,
        );
    }

    /**
     * ============================================================
     * PERMISSION DENIED
     * ============================================================
     */
    public function permissionDenied(
        string $permission,
        ?Model $resource = null,
        ?string $module = null,
        ?array $metadata = null,
    ): ?SystemLog {

        return $this->log(
            action: SystemLogAction::PERMISSION_DENIED,
            module: $module,
            resource: $resource,
            description:
                "Permission denied: {$permission}",
            metadata: array_merge(
                [
                    'permission' => $permission,
                ],
                $metadata ?? []
            ),
        );
    }

    /**
     * ============================================================
     * STATUS CHANGED
     * ============================================================
     */
    public function statusChanged(
        Model $resource,
        string $module,
        mixed $oldStatus,
        mixed $newStatus,
        ?string $description = null,
        ?array $metadata = null,
    ): ?SystemLog {

        return $this->log(
            action: SystemLogAction::STATUS_CHANGED,
            module: $module,
            resource: $resource,
            description:
                $description
                ?? 'Status changed',
            oldValues: [
                'status' => $oldStatus,
            ],
            newValues: [
                'status' => $newStatus,
            ],
            metadata: $metadata,
        );
    }

    /**
     * ============================================================
     * SANITIZATION
     * ============================================================
     *
     * Recursively removes/redacts sensitive values.
     */
    private function sanitize(
        mixed $value
    ): mixed {

        if ($value === null) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Arrays
        |--------------------------------------------------------------------------
        */

        if (is_array($value)) {

            $result = [];

            foreach ($value as $key => $item) {

                /*
                 * Normalize:
                 *
                 * password-confirmation
                 * password confirmation
                 * password_confirmation
                 *
                 * all become:
                 *
                 * password_confirmation
                 */

                $normalizedKey =
                    strtolower(
                        str_replace(
                            ['-', ' '],
                            '_',
                            (string) $key
                        )
                    );

                /*
                 * Never persist sensitive fields.
                 */

                if (
                    in_array(
                        $normalizedKey,
                        self::SENSITIVE_FIELDS,
                        true
                    )
                ) {
                    $result[$key] = '[REDACTED]';

                    continue;
                }

                $result[$key] =
                    $this->sanitize($item);
            }

            return $result;
        }

        /*
        |--------------------------------------------------------------------------
        | Eloquent Model
        |--------------------------------------------------------------------------
        */

        if ($value instanceof Model) {

            return [
                'id' => $value->getKey(),
                'type' => $value::class,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | JsonSerializable
        |--------------------------------------------------------------------------
        */

        if ($value instanceof \JsonSerializable) {

            return $this->sanitize(
                $value->jsonSerialize()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Other Objects
        |--------------------------------------------------------------------------
        */

        if (is_object($value)) {
            return '[OBJECT]';
        }

        /*
        |--------------------------------------------------------------------------
        | Scalar
        |--------------------------------------------------------------------------
        */

        return $value;
    }

    /**
     * ============================================================
     * USER AGENT
     * ============================================================
     */
    private function truncateUserAgent(
        ?string $userAgent
    ): ?string {

        if (!$userAgent) {
            return null;
        }

        return Str::limit(
            $userAgent,
            1000,
            ''
        );
    }
}
