<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,

    // Registered before AuthServiceProvider: Gate::before asks the user whether
    // they are a super admin, which reads the tenant-aware permission cache.
    TenancyServiceProvider::class,

    AuthServiceProvider::class,
];
