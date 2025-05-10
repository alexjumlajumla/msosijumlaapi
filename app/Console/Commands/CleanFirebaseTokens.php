<?php

namespace App\Console\Commands;

use App\Traits\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Google\Client;

class CleanFirebaseTokens extends Command
{
    use Notification;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firebase:clean-tokens {--test : Run a test notification} {--token= : Firebase token to test with}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up invalid and duplicate Firebase tokens and test functionality';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('test')) {
            return $this->testNotification();
        }
        
        $this->info('Starting Firebase token cleanup...');
        
        try {
            $this->cleanInvalidTokens();
            $this->info('Firebase token cleanup completed successfully.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Error cleaning Firebase tokens: ' . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Test Firebase notification
     */
    protected function testNotification()
    {
        $this->info('Testing Firebase notification system...');
        
        try {
            // Step 1: Check the service account file
            $serviceAccountPath = storage_path('app/google-service-account.json');
            if (!file_exists($serviceAccountPath)) {
                $this->error("Service account file not found: $serviceAccountPath");
                return 1;
            }
            $this->info("✓ Service account file exists");
            
            // Step 2: Try to read the file
            $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("Failed to parse service account file: " . json_last_error_msg());
                return 1;
            }
            $this->info("✓ Service account file is valid JSON");
            
            // Step 3: Check project ID
            $projectId = $serviceAccount['project_id'] ?? null;
            if (empty($projectId)) {
                $this->info("No project_id in service account, checking settings table...");
                $projectId = $this->projectId();
                if (empty($projectId)) {
                    $this->error("No project_id found in service account or settings");
                    return 1;
                }
            }
            $this->info("✓ Project ID found: $projectId");
            
            // Step 4: Try to get a token
            try {
                $googleClient = new Client;
                $googleClient->setAuthConfig($serviceAccountPath);
                $googleClient->addScope('https://www.googleapis.com/auth/firebase.messaging');
                
                $this->info("Fetching Firebase auth token...");
                $authToken = $googleClient->fetchAccessTokenWithAssertion()['access_token'] ?? null;
                
                if (empty($authToken)) {
                    $this->error("Failed to get Firebase auth token - empty response");
                    return 1;
                }
                $this->info("✓ Successfully got Firebase auth token");
                
                // Step 5: Send a test notification
                $token = $this->option('token');
                if (empty($token)) {
                    // If no token provided via option, try to grab one from the database
                    try {
                        $user = \App\Models\User::whereNotNull('firebase_token')
                            ->where('firebase_token', '!=', '')
                            ->where('firebase_token', '!=', '[]')
                            ->where('firebase_token', '!=', 'null')
                            ->first();
                        
                        if ($user) {
                            $token = $user->firebase_token;
                            $this->info("Using token from user #{$user->id}: " . substr($token, 0, 20) . "...");
                        }
                    } catch (\Exception $e) {
                        $this->warn("Error finding token in database: " . $e->getMessage());
                    }
                    
                    if (empty($token)) {
                        $this->error("No token provided. Please provide a token with --token option");
                        return 1;
                    }
                }
                
                // Check token format
                if (is_array($token)) {
                    $token = $token[0] ?? null;
                    if (empty($token)) {
                        $this->error("Token is an empty array");
                        return 1;
                    }
                    $this->info("Token was an array, using first element");
                }
                
                $this->info("Sending test notification to token: " . substr($token, 0, 20) . "...");
                
                // Format data values as strings for FCM
                $data = [
                    'type' => 'test',
                    'timestamp' => (string)time(),
                    'message' => 'Test message content'
                ];
                
                $notification = [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => 'Test Notification',
                            'body' => 'This is a test notification from your app',
                        ],
                        'data' => $data,
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'sound' => 'default',
                                'channel_id' => 'high_importance_channel'
                            ]
                        ],
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10'
                            ],
                            'payload' => [
                                'aps' => [
                                    'sound' => 'default',
                                    'badge' => 1,
                                    'content-available' => 1
                                ]
                            ]
                        ]
                    ]
                ];
                
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $authToken,
                    'Content-Type' => 'application/json',
                ])->post("https://fcm.googleapis.com/v1/projects/$projectId/messages:send", $notification);
                
                if ($response->successful()) {
                    $this->info("✓ Notification sent successfully!");
                    $this->info("Response: " . $response->body());
                    return 0;
                } else {
                    $this->error("Failed to send notification");
                    $this->error("Status: " . $response->status());
                    $this->error("Response: " . $response->body());
                    return 1;
                }
                
            } catch (\Exception $e) {
                $this->error("Error with Firebase auth: " . $e->getMessage());
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error("Error testing Firebase: " . $e->getMessage());
            return 1;
        }
    }
} 