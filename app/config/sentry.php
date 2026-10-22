<?php

declare(strict_types=1);

use App\Observability\SentryScrubber;

/*
 * Sentry (self-hosted, sentry.karlovo.net, project `chirak`). Inactive until
 * SENTRY_LARAVEL_DSN is set: the DSN lives in 1Password vault "AI Agent", item
 * "Chirak Sentry", field `dsn`.
 *
 * Training examples may contain customer text. No default PII, no request
 * bodies, no SQL bindings, and every event and breadcrumb passes SentryScrubber.
 * These are fixed here, not env switches.
 */
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'release' => env('SENTRY_RELEASE'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    'sample_rate' => 1.0,
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,
    'enable_logs' => false,
    'enable_metrics' => false,

    'send_default_pii' => false,
    'max_request_body_size' => 'never',
    'before_send' => [SentryScrubber::class, 'event'],
    'before_breadcrumb' => [SentryScrubber::class, 'breadcrumb'],

    'ignore_transactions' => ['/up'],

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => false,
    ],

    'tracing' => [
        'default_integrations' => true,
    ],
];
