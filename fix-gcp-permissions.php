<?php
/**
 * Google Cloud Platform Credentials Permissions Fix Script
 * 
 * This script will:
 * 1. Check if the Google credentials file exists
 * 2. Verify its contents (must be valid JSON)
 * 3. Fix file permissions if needed
 * 4. Create a backup in an accessible location
 */

// Show all errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "=== Google Cloud Credentials Check & Fix Tool ===\n\n";

// Get the credentials path from environment
$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');

echo "Checking credentials at path: $credentialsPath\n";

// Check if the credentials path is set
if (empty($credentialsPath)) {
    echo "ERROR: GOOGLE_APPLICATION_CREDENTIALS is not set in environment.\n";
    exit(1);
}

// Check if the file exists
if (!file_exists($credentialsPath)) {
    echo "ERROR: Credentials file does not exist at $credentialsPath\n";
    exit(1);
}

// Check if the file is readable
if (!is_readable($credentialsPath)) {
    echo "WARNING: Credentials file exists but is not readable.\n";
    
    // Try to fix permissions
    echo "Attempting to fix permissions...\n";
    chmod($credentialsPath, 0644);
    
    if (!is_readable($credentialsPath)) {
        echo "ERROR: Could not make the credentials file readable.\n";
        exit(1);
    }
    
    echo "Successfully fixed file permissions.\n";
}

// Read the file content
$credentialsContent = file_get_contents($credentialsPath);

// Verify it's valid JSON
$jsonData = json_decode($credentialsContent);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "ERROR: Credentials file is not valid JSON. Error: " . json_last_error_msg() . "\n";
    exit(1);
}

echo "Credentials file exists and contains valid JSON.\n";

// Check for required fields in the credentials JSON
$requiredFields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email'];
$missingFields = [];

foreach ($requiredFields as $field) {
    if (!property_exists($jsonData, $field)) {
        $missingFields[] = $field;
    }
}

if (!empty($missingFields)) {
    echo "WARNING: Credentials file is missing required fields: " . implode(', ', $missingFields) . "\n";
}

// Create an accessible backup
$storageDir = __DIR__ . '/storage/app/';
$backupPath = $storageDir . 'google-service-account.json';

if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

echo "Creating backup at $backupPath\n";
copy($credentialsPath, $backupPath);
chmod($backupPath, 0644);

if (file_exists($backupPath)) {
    echo "SUCCESS: Backup created successfully.\n";
    echo "You can now use this path in your code: $backupPath\n";
} else {
    echo "ERROR: Failed to create backup.\n";
}

// Check if we need to update the environment variable
if (strpos($credentialsPath, 'storage/app/google-service-account.json') === false) {
    echo "\nSUGGESTION: Consider updating your GOOGLE_APPLICATION_CREDENTIALS in .env.local to:\n";
    echo "GOOGLE_APPLICATION_CREDENTIALS=" . realpath($backupPath) . "\n";
}

echo "\nGCP Credentials check complete.\n"; 