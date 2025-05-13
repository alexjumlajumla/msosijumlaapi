<?php

return [
    // ... existing code ...

    'vfd' => [
        'base_url' => env('VFD_BASE_URL', 'https://vfd-api.example.com'),
        'api_key' => env('VFD_API_KEY'),
        'tin' => env('VFD_TIN'),
        'cert_path' => env('VFD_CERT_PATH'),
    ],

    'google' => [
        'project_id' => env('GOOGLE_CLOUD_PROJECT', 'msosijumla'),
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    // ... existing code ...
]; 