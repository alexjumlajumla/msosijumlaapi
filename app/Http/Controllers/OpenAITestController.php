<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Orhanerday\OpenAi\OpenAi;
use Exception;
use Illuminate\Support\Facades\Log;

class OpenAITestController extends Controller
{
    /**
     * Test OpenAI API with chat completion
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testChatCompletion(Request $request)
    {
        try {
            $isGetRequest = $request->isMethod('get');
            $apiKey = $request->input('api_key') ?: config('services.openai.api_key');
            
            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'OpenAI API key is not configured',
                    'error_type' => 'missing_api_key',
                    'steps_to_fix' => [
                        'Add OPENAI_API_KEY to your .env file',
                        'Ensure the API key is valid and has appropriate permissions'
                    ]
                ], 500);
            }
            
            // Check if API key has the correct format
            if (!preg_match('/^(sk-|sk-org-)/', $apiKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid API key format. OpenAI keys should start with "sk-" or "sk-org-"',
                    'error_type' => 'invalid_format',
                    'steps_to_fix' => [
                        'Ensure you\'re using a valid API key from OpenAI',
                        'Check that you\'ve copied the full API key correctly'
                    ]
                ], 400);
            }
            
            $openAi = new OpenAi($apiKey);
            
            // For GET requests, use a predefined simple test message
            if ($isGetRequest) {
                Log::info('Handling GET request to OpenAI chat endpoint with simple test');
                $formattedMessages = [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant.'
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Say hello in a friendly way'
                    ]
                ];
                $model = 'gpt-3.5-turbo';
                $temperature = 0.7;
                $maxTokens = 50;
            } else {
                // For POST requests, get parameters from the request body
                $messages = $request->input('messages', []);
                $model = $request->input('model', 'gpt-3.5-turbo');
                $temperature = $request->input('temperature', 0.7);
                $maxTokens = $request->input('max_tokens', 150);
                
                // Format messages for the chat API
                $formattedMessages = [];
                
                if (empty($messages)) {
                    $formattedMessages = [
                        [
                            'role' => 'system',
                            'content' => 'You are a helpful assistant.'
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Hello, this is a test message to verify API connectivity.'
                        ]
                    ];
                    Log::info('No user message provided, using test prompt');
                } else {
                    // Convert the input messages to OpenAI format
                    foreach ($messages as $message) {
                        if (is_array($message) && isset($message['role']) && isset($message['content'])) {
                            $formattedMessages[] = [
                                'role' => $message['role'],
                                'content' => $message['content']
                            ];
                        }
                    }
                    
                    // Add a system message if not present
                    if (!array_filter($formattedMessages, function($msg) { 
                        return $msg['role'] === 'system'; 
                    })) {
                        array_unshift($formattedMessages, [
                            'role' => 'system',
                            'content' => 'You are a helpful assistant.'
                        ]);
                    }
                }
            }
            
            // Use chat API with the new package version
            $response = $openAi->chat([
                'model' => $model,
                'messages' => $formattedMessages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens
            ]);
            
            $decodedResponse = json_decode($response, true);
            
            // Check for specific error types
            if (isset($decodedResponse['error'])) {
                $errorMessage = $decodedResponse['error']['message'] ?? 'Unknown error';
                $errorType = $decodedResponse['error']['type'] ?? 'unknown_error';
                
                // Handle quota exceeded errors
                if (strpos($errorMessage, 'exceeded your current quota') !== false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'OpenAI API error: ' . $errorMessage,
                        'error_type' => 'quota_exceeded',
                        'steps_to_fix' => [
                            'Check your OpenAI account billing status',
                            'Add a payment method or upgrade your plan',
                            'Visit https://platform.openai.com/account/billing to manage your billing'
                        ],
                        'response' => $decodedResponse
                    ], 402); // 402 Payment Required
                }
                
                // Handle incorrect API key errors
                if (strpos($errorMessage, 'Incorrect API key provided') !== false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'OpenAI API error: ' . $errorMessage,
                        'error_type' => 'invalid_key',
                        'steps_to_fix' => [
                            'Check that you\'re using the correct API key from your OpenAI account',
                            'Ensure the API key is copied correctly with no extra spaces',
                            'Try generating a new API key from https://platform.openai.com/account/api-keys'
                        ],
                        'response' => $decodedResponse
                    ], 401); // 401 Unauthorized
                }
                
                // General error handling
                return response()->json([
                    'success' => false,
                    'message' => 'OpenAI API error: ' . $errorMessage,
                    'error_type' => $errorType,
                    'response' => $decodedResponse
                ], 500);
            }
            
            // Format the successful response
            if (isset($decodedResponse['choices']) && count($decodedResponse['choices']) > 0) {
                // For GET requests, simplify the response format
                if ($isGetRequest) {
                    return response()->json([
                        'success' => true,
                        'message' => 'OpenAI API is working properly',
                        'content' => $decodedResponse['choices'][0]['message']['content'] ?? '',
                        'model' => $decodedResponse['model'] ?? 'unknown'
                    ]);
                }
                
                // For POST requests, return the full response data
                return response()->json([
                    'success' => true,
                    'response' => $decodedResponse
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'OpenAI API error: Invalid response format',
                    'response' => $decodedResponse
                ], 500);
            }
            
        } catch (Exception $e) {
            Log::error('OpenAI API error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'OpenAI API error: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 