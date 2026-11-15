<?php

// Restrictive CORS: only the configured frontend origin(s), with credentials (Sanctum SPA cookies).
// In the recommended deployment the browser talks to Next.js only (same origin) and Next.js proxies
// /api and /sanctum to Laravel, so CORS is not even used; this is a safety net for direct access.
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => true,
];
