<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\Contracts\TenantResolver;
use App\Support\Tenancy\Resolvers\DomainTenantResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantHost;
use App\Support\Tenancy\TenantResolverChain;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton: the whole request shares one context, and the global query
        // scope reads it out of the container on every query.
        $this->app->singleton(TenantContext::class);

        $this->app->singleton(TenantHost::class);
        $this->app->singleton(TenantUrl::class);
        $this->app->singleton(TenantResolverChain::class);

        $this->app->bind(TenantResolver::class, TenantResolverChain::class);

        $this->app->when(DomainTenantResolver::class)
            ->needs(CacheRepository::class)
            ->give(fn () => Cache::store(config('tenancy.cache.store')));
    }

    public function boot(): void
    {
        // Queued jobs run in a long-lived worker process. Without this, a job
        // for School A could inherit the context of the job that ran before it.
        $this->app['events']->listen(
            \Illuminate\Queue\Events\JobProcessing::class,
            fn () => $this->app->make(TenantContext::class)->forget(),
        );

        $this->app['events']->listen(
            \Illuminate\Queue\Events\JobProcessed::class,
            fn () => $this->app->make(TenantContext::class)->forget(),
        );

        $this->app['events']->listen(
            \Illuminate\Queue\Events\JobFailed::class,
            fn () => $this->app->make(TenantContext::class)->forget(),
        );
    }
}
