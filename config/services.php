<?php

return [
    // ... existing code ...

    'vfd' => [
        'base_url' => env('VFD_BASE_URL', 'https://vfd-api.example.com'),
        'api_key' => env('VFD_API_KEY'),
        'tin' => env('VFD_TIN'),
        'cert_path' => env('VFD_CERT_PATH'),
    ],
    
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
        'temperature' => env('OPENAI_TEMPERATURE', 0.7),
    ],

    'google' => [
        // Try the environment variable first
        'credentials' => function() {
            $primaryPath = env('GOOGLE_APPLICATION_CREDENTIALS');
            $fallbackPath1 = storage_path('app/google-service-account.json');
            $fallbackPath2 = storage_path('app/jumlajumla-1f0f0-98ab02854aef.json');
            
            // Check if the primary path exists and is readable
            if (!empty($primaryPath) && file_exists($primaryPath) && is_readable($primaryPath)) {
                return $primaryPath;
            }
            
            // Try the standard fallback path
            if (file_exists($fallbackPath1) && is_readable($fallbackPath1)) {
                return $fallbackPath1;
            }
            
            // Try the specific filename fallback
            if (file_exists($fallbackPath2) && is_readable($fallbackPath2)) {
                return $fallbackPath2;
            }
            
            // Return the environment variable as a last resort
            return $primaryPath;
        },
        'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
    ],

    // ... existing code ...
]; 