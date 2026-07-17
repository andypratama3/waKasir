<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CORS Configuration
    |--------------------------------------------------------------------------
    | JANGAN pakai wildcard (*) bersamaan dengan supports_credentials = true.
    | Itu kombinasi yang dilarang oleh spesifikasi CORS dan membuka celah CSRF.
    |
    | Di production, isi FRONTEND_URL di .env dengan domain Angular kamu:
    |   FRONTEND_URL=https://app.wakasir.com
    |
    | Di development, default ke localhost:4200.
    --------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Specific origins only — NEVER '*' when supports_credentials is true
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:4200'),
        env('FRONTEND_URL_EXTRA'),          // opsional: staging domain kedua
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => [],

    'max_age' => 7200,

    'supports_credentials' => true,

];
