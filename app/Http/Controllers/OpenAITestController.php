<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Orhanerday\OpenAi\OpenAi;
use Exception;
use Illuminate\Support\Facades\Log;

class OpenAITestController extends Controller
{
    /**
     * Test OpenAI API with basic completion
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testChatCompletion(Request $request)
    {
        try {
            $apiKey = config('services.openai.api_key');
            
            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'OpenAI API key is not configured',
                    'steps_to_fix' => [
                        'Add OPENAI_API_KEY to your .env file',
                        'Ensure the API key is valid and has appropriate permissions'
                    ]
                ], 500);
            }
            
            $openAi = new OpenAi($apiKey);
            
            $messages = $request->input('messages', []);
            $model = $request->input('model', 'gpt-3.5-turbo-instruct');
            $temperature = $request->input('temperature', 0.7);
            $maxTokens = $request->input('max_tokens', 150);
            
            // Extract the prompt from messages
            $prompt = '';
            foreach ($messages as $message) {
                if ($message['role'] === 'user') {
                    $prompt .= $message['content'] . "\n";
                }
            }
            
            if (empty($prompt)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No user message provided in the request'
                ], 400);
            }
            
            // Use completion API for older versions of the package
            $response = $openAi->completion([
                'model' => $model,
                'prompt' => $prompt,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'frequency_penalty' => 0,
                'presence_penalty' => 0
            ]);
            
            $decodedResponse = json_decode($response, true);
            
            // Format the response to mimic chat completion
            if (isset($decodedResponse['choices']) && count($decodedResponse['choices']) > 0) {
                $formattedResponse = [
                    'id' => $decodedResponse['id'] ?? uniqid(),
                    'object' => 'chat.completion',
                    'created' => $decodedResponse['created'] ?? time(),
                    'model' => $decodedResponse['model'] ?? $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant', 
                                'content' => $decodedResponse['choices'][0]['text'] ?? ''
                            ],
                            'finish_reason' => $decodedResponse['choices'][0]['finish_reason'] ?? 'stop'
                        ]
                    ],
                    'usage' => $decodedResponse['usage'] ?? [
                        'prompt_tokens' => strlen($prompt) / 4,
                        'completion_tokens' => strlen($decodedResponse['choices'][0]['text'] ?? '') / 4,
                        'total_tokens' => (strlen($prompt) + strlen($decodedResponse['choices'][0]['text'] ?? '')) / 4
                    ]
                ];
                
                return response()->json([
                    'success' => true,
                    'response' => $formattedResponse
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'OpenAI API error: ' . ($decodedResponse['error']['message'] ?? 'Unknown error'),
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