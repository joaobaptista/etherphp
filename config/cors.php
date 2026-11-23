<?php

$isProduction = env('APP_ENV', 'production') === 'production';

$configuredOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', implode(',', array_filter([
        (string) env('CORS_ORIGINS', ''),
        (string) env('CLIENT_URL', ''),
        (string) env('FRONTEND_URL', ''),
        (string) env('APP_URL', ''),
    ]))),
)));

$devOrigins = $isProduction ? [] : [
    'http://localhost:8000',
    'http://127.0.0.1:8000',
    'http://localhost:3333',
    'http://127.0.0.1:3333',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:28080',
    'http://127.0.0.1:28080',
];

$allowedOrigins = array_values(array_unique(array_merge($configuredOrigins, $devOrigins)));

return [
    'paths' => ['api/*', 'uploads/*', 'health', 'metrics'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Range', 'Accept-Ranges', 'X-Ethernity-Variant'],
    'max_age' => 0,
    'supports_credentials' => true,
];
