<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | Sanctum's SPA authentication sends credentialed requests (cookies), and
    | the credentials mode of CORS forbids a wildcard origin outright — the
    | allow-list has to be explicit. Every school's own subdomain has to be
    | allowed without listing each one by hand, hence the wildcard pattern.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),

    // *.ntcsweb.com covers every school's subdomain; central/local origins are
    // added explicitly via CORS_ALLOWED_ORIGINS in .env.
    'allowed_origins_patterns' => [
        '#^https?://([a-z0-9-]+\.)*'.preg_quote(env('TENANCY_ROOT_DOMAIN', 'ntcsweb.com'), '#').'$#i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Tenant-Id'],

    'max_age' => 0,

    'supports_credentials' => true,

];
