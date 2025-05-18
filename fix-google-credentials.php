<?php
/**
 * Fix Google Cloud credentials file
 * 
 * This script checks for Google Cloud credentials and makes a copy in the expected location
 * if needed.
 */

// Display all errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "=== Google Cloud Credentials Fix ===\n\n";

// Get the credentials path from environment
$envCredentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
$expectedPath = __DIR__ . '/storage/app/google-service-account.json';

echo "Environment credentials path: $envCredentialsPath\n";
echo "Expected credentials path: $expectedPath\n\n";

// Check if the environment variable is set
if (empty($envCredentialsPath)) {
    echo "ERROR: GOOGLE_APPLICATION_CREDENTIALS environment variable is not set.\n";
    exit(1);
}

// Check if the original file exists
if (!file_exists($envCredentialsPath)) {
    echo "ERROR: Original credentials file not found at $envCredentialsPath\n";
    exit(1);
}

// Make sure storage/app directory exists
$storageAppDir = __DIR__ . '/storage/app';
if (!file_exists($storageAppDir)) {
    echo "Creating storage/app directory...\n";
    if (!mkdir($storageAppDir, 0755, true)) {
        echo "ERROR: Could not create directory $storageAppDir\n";
        exit(1);
    }
}

// Copy the file to the expected location
echo "Copying credentials file to expected location...\n";
if (copy($envCredentialsPath, $expectedPath)) {
    echo "SUCCESS: Credentials file copied to $expectedPath\n";
    
    // Update permissions
    chmod($expectedPath, 0644);
    echo "File permissions set to 0644\n";
    
    // Verify the file
    if (file_exists($expectedPath) && is_readable($expectedPath)) {
        echo "Verifying file...\n";
        $contents = file_get_contents($expectedPath);
        $credentials = json_decode($contents, true);
        
        if (json_last_error() === JSON_ERROR_NONE && !empty($credentials['project_id'])) {
            echo "Verification successful. Project ID: " . $credentials['project_id'] . "\n";
        } else {
            echo "WARNING: File copied but JSON verification failed.\n";
        }
    }
    
    // Suggest updating environment variable
    echo "\nRecommendation: Update your .env.local file to use this path:\n";
    echo "GOOGLE_APPLICATION_CREDENTIALS=$expectedPath\n";
} else {
    echo "ERROR: Failed to copy credentials file.\n";
    exit(1);
} 