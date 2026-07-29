<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // In production the SPA is served from FRONTEND_URL; dev uses the Vite proxy
    // (same origin), so no wildcard is ever needed.
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
        env('APP_URL'),
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
