<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\Contracts\TenantResolver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Runs the configured resolvers in order and returns the first tenant found.
 *
 * Also a TenantResolver itself, so it can be nested or swapped out wholesale in
 * tests.
 */
final class TenantResolverChain implements TenantResolver
{
    public function __construct(private readonly Container $container) {}

    public function resolve(Request $request): ?Tenant
    {
        foreach ((array) config('tenancy.resolvers', []) as $class) {
            $resolver = $this->container->make($class);

            if (! $resolver instanceof TenantResolver) {
                throw new InvalidArgumentException(
                    sprintf('[%s] listed in tenancy.resolvers must implement %s.', $class, TenantResolver::class)
                );
            }

            if ($tenant = $resolver->resolve($request)) {
                return $tenant;
            }
        }

        return null;
    }
}
