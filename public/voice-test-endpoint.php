<?php
/**
 * Simple test script to verify the voice order API endpoint is working
 */

// Disable error reporting for production
error_reporting(0);
ini_set('display_errors', 0);

// Set response headers
header('Content-Type: application/json');

try {
    // Create cURL request to the internal API
    $ch = curl_init();
    
    // Set the URL to the voice order endpoint
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/api/v1/voice-order/test-transcribe');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    // Add some test data to the request
    $postData = [
        'test' => true,
        'language' => 'en-US',
        'timestamp' => time()
    ];
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    
    // Add headers
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'X-Voice-Test: 1'
    ]);
    
    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    // Output the results
    echo json_encode([
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode,
        'response' => json_decode($response, true),
        'error' => $error,
        'test_url' => '/api/v1/voice-order/test-transcribe',
        'server_info' => [
            'php_version' => PHP_VERSION,
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'time' => date('Y-m-d H:i:s')
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Return the error
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
} 