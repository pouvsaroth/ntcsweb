<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // See App\Services\Billing\Notifications\TelegramChannel. The bot token
    // lives here (env-only), never in the database — a `recipient` is a
    // per-send Telegram chat ID, not a secret, so that one does travel with
    // the request/NotificationLog row.
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    ],

    // See App\Services\Billing\InvoicePdfService. Browsershot drives a
    // system-installed Chromium (docker/php/Dockerfile) rather than
    // downloading its own — chrome_path points at it directly, and
    // node_modules_path tells the node_modules-resolution Browsershot's
    // bundled script does where to find the globally-installed
    // puppeteer-core (installed outside /var/www/html because that
    // directory is shadowed at runtime by the ./backend bind mount).
    'browsershot' => [
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH', '/usr/bin/chromium'),
        'node_modules_path' => env('BROWSERSHOT_NODE_MODULES_PATH'),
    ],

];
