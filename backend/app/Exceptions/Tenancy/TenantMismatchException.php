<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a record's tenant does not match the tenant in context — either
 * an authenticated user reaching for another school's data, or application code
 * trying to write a row into the wrong school.
 *
 * Deliberately reported as 403 with no detail about what exists on the other
 * side, so it cannot be used to probe for records in other tenants.
 */
class TenantMismatchException extends \RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        string $message = 'This resource does not belong to the current tenant.',
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function forUser(int $userTenantId, ?int $resolvedTenantId): self
    {
        return new self(
            'Your account does not belong to the requested tenant.',
            ['user_tenant_id' => $userTenantId, 'resolved_tenant_id' => $resolvedTenantId],
        );
    }

    public static function forModel(string $model, ?int $modelTenantId, ?int $currentTenantId): self
    {
        return new self(
            sprintf('Refusing to persist [%s] across tenant boundaries.', $model),
            ['model_tenant_id' => $modelTenantId, 'current_tenant_id' => $currentTenantId],
        );
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_FORBIDDEN;
    }

    public function getHeaders(): array
    {
        return [];
    }

    /**
     * Detail goes to the log, never to the client.
     */
    public function context(): array
    {
        return $this->context;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error' => ['code' => 'TENANT_MISMATCH'],
        ], Response::HTTP_FORBIDDEN);
    }
}
