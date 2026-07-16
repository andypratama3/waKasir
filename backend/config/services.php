<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    ],

    'rajaongkir' => [
        'api_key' => env('RAJAONGKIR_API_KEY'),
        'base_url' => env('RAJAONGKIR_BASE_URL', 'https://api.rajaongkir.com/starter'),
    ],

    'whatsapp' => [
        'phone_id'              => env('WA_PHONE_ID'),
        'access_token'          => env('WA_ACCESS_TOKEN'),                      // global fallback (dev only)
        'verify_token'          => env('WA_WEBHOOK_VERIFY_TOKEN', 'wa_verify_default'),
        'base_url'              => env('WA_BASE_URL', 'https://graph.facebook.com/v18.0'),
        // BSP Embedded Signup — isi sekali oleh kamu (pemilik WaKasir)
        'app_id'                => env('META_APP_ID'),
        'app_secret'            => env('META_APP_SECRET'),
        'config_id'             => env('META_EMBEDDED_SIGNUP_CONFIG_ID'),       // dari WhatsApp Manager
    ],

];