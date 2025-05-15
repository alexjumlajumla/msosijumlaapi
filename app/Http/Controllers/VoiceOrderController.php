<?php

namespace App\Http\Controllers;

use App\Services\VoiceOrderService;
use App\Services\AIOrderService;
use App\Services\FoodIntelligenceService;
use App\Models\User;
use App\Models\AIAssistantLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class VoiceOrderController extends Controller
{
    protected VoiceOrderService $voiceOrderService;
    protected AIOrderService $aiOrderService;
    protected FoodIntelligenceService $foodIntelligenceService;

    public function __construct(
        VoiceOrderService $voiceOrderService, 
        AIOrderService $aiOrderService,
        FoodIntelligenceService $foodIntelligenceService
    ) {
        $this->voiceOrderService = $voiceOrderService;
        $this->aiOrderService = $aiOrderService;
        $this->foodIntelligenceService = $foodIntelligenceService;
    }

    public function processVoiceOrder(Request $request)
    {
        $startTime = microtime(true);
        $userId = Auth::id();
        $success = false;
        $sessionId = $request->input('session_id', md5(uniqid()));
        
        $logData = [
            'user_id' => $userId,
            'request_type' => 'voice_order',
            'successful' => false,
            'session_id' => $sessionId,
            'metadata' => [] // Initialize metadata
        ];

        try {
            // Check if user has enough credits
            if (Auth::check()) {
                $user = Auth::user();
                if (!$this->checkVoiceOrderCredits($user)) {
                    return response()->json([
                        'success' => false, 
                        'message' => 'You have used all your voice order credits. Please upgrade to continue.',
                        'upgrade_required' => true
                    ], 403);
                }
            }

            $audioFilePath = $request->file('audio')->getPathname();
            $language = $request->input('language', 'en-US');
            
            // Check for cached transcription with same audio hash
            $audioHash = md5_file($audioFilePath);
            $cachedTranscription = Cache::get('voice_transcription_' . $audioHash);
            
            if ($cachedTranscription) {
                $transcription = $cachedTranscription;
                $logData['metadata']['cached'] = true;
            } else {
                // Transcribe the audio with language preference
                $transcription = $this->voiceOrderService->transcribeAudio($audioFilePath, $language);
                
                // Cache the transcription for 2 minutes
                Cache::put('voice_transcription_' . $audioHash, $transcription, 120);
                $logData['metadata']['cached'] = false;
            }
            
            // Process the transcript with user context if available
            $user = Auth::check() ? Auth::user() : null;
            
            // Ensure transcription is a string - fixes the "Argument #1 ($transcription) must be of type string" error
            $transcriptionText = is_array($transcription) ? 
                ($transcription['transcription'] ?? '') : 
                (is_string($transcription) ? $transcription : '');
                
            // Fix: Pass transcription as string to processOrderIntent
            $orderData = $this->aiOrderService->processOrderIntent($transcriptionText, $user);
            
            // Get conversation context if this is a follow-up
            $previousContext = $this->getPreviousContext($sessionId);
            if ($previousContext && !empty($previousContext['orderData'])) {
                $orderData = $this->mergeWithPreviousContext($orderData, $previousContext['orderData']);
            }

            // Update log data with input and filters
            $logData['input'] = $transcriptionText;
            $logData['request_content'] = $transcriptionText;
            $logData['filters_detected'] = $orderData;
            $logData['metadata']['order_data'] = $orderData;

            // Get product recommendations based on order data
            $recommendations = $this->foodIntelligenceService->filterProducts($orderData);
            
            // Generate a recommendation explanation
            $recommendationText = $this->aiOrderService->generateRecommendation($orderData);
            
            // Update log data with recommendations
            $logData['product_ids'] = $recommendations->pluck('id')->toArray();
            $logData['metadata']['products'] = $recommendations->pluck('id')->toArray();
            $logData['metadata']['recommendation_text'] = $recommendationText;
            $logData['output'] = $recommendationText;
            $logData['response_content'] = $recommendationText;
            
            // Save context for follow-up questions
            $this->saveConversationContext($sessionId, [
                'transcription' => $transcriptionText,
                'orderData' => $orderData,
                'timestamp' => time()
            ]);

            // Decrement user's voice order credits if authenticated
            if (Auth::check()) {
                $this->decrementVoiceOrderCredits(Auth::user());
            }

            $success = true;
            
            // Create the log entry
            $logData['successful'] = $success;
            $logData['processing_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $logEntry = AIAssistantLog::create($logData);
            
            return response()->json([
                'success' => true, 
                'transcription' => $transcriptionText,
                'intent_data' => $orderData,
                'recommendations' => $recommendations,
                'recommendation_text' => $recommendationText,
                'session_id' => $sessionId,
                'log_id' => $logEntry->id
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing voice order: ' . $e->getMessage() . ' | ' . $e->getTraceAsString());
            $logData['output'] = $e->getMessage();
            $logData['response_content'] = $e->getMessage();
            
            // More detailed error for debugging
            $errorDetails = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString())
            ];
            
            // Calculate processing time
            $logData['processing_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $logData['successful'] = false;
            $logEntry = AIAssistantLog::create($logData);
            
            return response()->json([
                'success' => false, 
                'message' => 'Failed to process voice order. Error: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'error_details' => $errorDetails,
                'log_id' => $logEntry->id
            ], 500);
        }
    }
    
    /**
     * Process feedback for a voice order recommendation
     */
    public function processFeedback(Request $request)
    {
        $request->validate([
            'log_id' => 'required|exists:a_i_assistant_logs,id',
            'helpful' => 'required|boolean',
            'feedback' => 'nullable|string|max:500'
        ]);
        
        try {
            $logEntry = AIAssistantLog::findOrFail($request->input('log_id'));
            
            // Update the log entry with feedback
            $logEntry->update([
                'feedback' => [
                    'helpful' => $request->boolean('helpful'),
                    'comment' => $request->input('feedback'),
                    'timestamp' => now()->toDateTimeString()
                ],
                'is_feedback_provided' => true,
                'was_helpful' => $request->boolean('helpful')
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Feedback recorded successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error recording feedback: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to record feedback'
            ], 500);
        }
    }
    
    /**
     * Get previous conversation context from cache
     */
    private function getPreviousContext(string $sessionId): ?array
    {
        return Cache::get('voice_order_context_' . $sessionId);
    }
    
    /**
     * Save conversation context to cache
     */
    private function saveConversationContext(string $sessionId, array $context): void
    {
        // Save for 30 minutes
        Cache::put('voice_order_context_' . $sessionId, $context, 1800);
    }
    
    /**
     * Merge current order data with previous context for continuous conversation
     */
    private function mergeWithPreviousContext(array $currentData, array $previousData): array
    {
        // If current intent is empty but previous had one, keep the previous
        if (empty($currentData['intent']) && !empty($previousData['intent'])) {
            $currentData['intent'] = $previousData['intent'];
        }
        
        // Merge filters and remove duplicates
        if (!empty($previousData['filters'])) {
            $currentData['filters'] = array_values(array_unique(
                array_merge($currentData['filters'] ?? [], $previousData['filters'])
            ));
        }
        
        // Merge exclusions and remove duplicates
        if (!empty($previousData['exclusions'])) {
            $currentData['exclusions'] = array_values(array_unique(
                array_merge($currentData['exclusions'] ?? [], $previousData['exclusions'])
            ));
        }
        
        // Use previous cuisine type if current is empty
        if (empty($currentData['cuisine_type']) && !empty($previousData['cuisine_type'])) {
            $currentData['cuisine_type'] = $previousData['cuisine_type'];
        }
        
        return $currentData;
    }

    /**
     * Check if user has voice order credits available
     */
    private function checkVoiceOrderCredits(User $user): bool
    {
        // Free tier users get 3 credits by default
        $credits = $user->ai_order_credits ?? 3;
        
        // Subscribers have unlimited credits
        if ($user->is_premium) {
            return true;
        }
        
        return $credits > 0;
    }
    
    /**
     * Decrement user's voice order credits
     */
    private function decrementVoiceOrderCredits(User $user): void
    {
        // Skip for premium users
        if ($user->is_premium) {
            return;
        }
        
        // Ensure credits don't go below zero
        if ($user->ai_order_credits > 0) {
            $user->decrement('ai_order_credits');
        }
    }
    
    /**
     * Public method to access audio transcription for testing
     * 
     * @param \Illuminate\Http\UploadedFile|Request $audioFile
     * @param string $language Language code, default en-US
     * @return array|\Illuminate\Http\JsonResponse Transcription result with text and confidence
     */
    public function transcribeAudio($audioFile, string $language = 'en-US')
    {
        try {
            // Handle both direct calls and HTTP requests
            if ($audioFile instanceof Request) {
                $request = $audioFile;
                $audioFile = $request->file('audio');
                $language = $request->input('language', 'en-US');
                
                if (!$audioFile) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No audio file provided'
                    ], 400);
                }
            }
            
            $filePath = $audioFile->getPathname();
            
            // Check for cached transcription
            $audioHash = md5_file($filePath);
            $cachedResult = Cache::get('voice_transcription_' . $audioHash);
            
            if ($cachedResult) {
                $result = $cachedResult;
                $isCached = true;
            } else {
                $result = $this->voiceOrderService->transcribeAudio($filePath, $language);
                
                // Cache result for 2 minutes if successful
                if (isset($result['success']) && $result['success']) {
                    Cache::put('voice_transcription_' . $audioHash, $result, 120);
                }
                $isCached = false;
            }
            
            // Check if transcription was successful
            if (!isset($result['success']) || !$result['success']) {
                $response = [
                    'success' => false,
                    'error' => $result['error'] ?? 'Unknown error during transcription',
                    'text' => '',
                    'language' => $language
                ];
                
                return ($audioFile instanceof Request) ? response()->json($response) : $response;
            }
            
            $response = [
                'success' => true, 
                'text' => $result['transcription'],
                'confidence' => $result['confidence'] ?? 0,
                'language' => $language,
                'timestamps' => $result['word_timestamps'] ?? [],
                'cached' => $isCached
            ];
            
            return ($audioFile instanceof Request) ? response()->json($response) : $response;
            
        } catch (\Exception $e) {
            Log::error('Transcription error in controller', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $response = [
                'success' => false,
                'error' => $e->getMessage(),
                'text' => '',
                'language' => $language
            ];
            
            return ($audioFile instanceof Request) ? response()->json($response, 500) : $response;
        }
    }
    
    /**
     * Process audio for real-time transcription
     * This is a lighter version of the full voice order processing
     * that only returns the transcription without AI analysis
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function realtimeTranscription(Request $request)
    {
        $startTime = microtime(true);
        $userId = Auth::id();
        $success = false;
        
        $logData = [
            'user_id' => $userId,
            'request_type' => 'realtime_transcription',
            'successful' => false,
            'metadata' => [] // Initialize metadata
        ];
        
        try {
            $audioFile = $request->file('audio');
            $language = $request->input('language', 'en-US');
            
            if (!$audioFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'No audio file provided'
                ], 400);
            }
            
            // Check for cached transcription
            $audioHash = md5_file($audioFile->getPathname());
            $cachedResult = Cache::get('voice_transcription_' . $audioHash);
            
            if ($cachedResult) {
                $result = $cachedResult;
                $logData['metadata']['cached'] = true;
            } else {
                // Just transcribe the audio without further processing
                $result = $this->transcribeAudio($audioFile, $language);
                $logData['metadata']['cached'] = false;
            }
            
            $logData['input'] = $result['text'] ?? '';
            $logData['request_content'] = $result['text'] ?? '';
            $logData['output'] = $result['text'] ?? '';
            $logData['response_content'] = $result['text'] ?? '';
            $success = isset($result['success']) ? $result['success'] : false;
            
            // Calculate processing time and update success
            $logData['processing_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $logData['successful'] = $success;
            $logEntry = AIAssistantLog::create($logData);
            
            return response()->json([
                'success' => $success,
                'text' => $result['text'] ?? '',
                'language' => $language,
                'confidence' => $result['confidence'] ?? 0,
                'log_id' => $logEntry->id
            ]);
        } catch (\Exception $e) {
            \Log::error('Real-time transcription failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            // Calculate processing time
            $logData['processing_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $logData['successful'] = false;
            $logEntry = AIAssistantLog::create($logData);
            
            return response()->json([
                'success' => false,
                'message' => 'Transcription failed: ' . $e->getMessage(),
                'text' => '',
                'log_id' => $logEntry->id
            ], 500);
        }
    }
    
    /**
     * Repeat a previous voice order using its session ID
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function repeatOrder(Request $request)
    {
        $startTime = microtime(true);
        $userId = Auth::id();
        $success = false;
        
        $logData = [
            'user_id' => $userId,
            'request_type' => 'repeat_order',
            'successful' => false,
            'metadata' => [] // Initialize metadata
        ];
        
        try {
            $sessionId = $request->input('session_id');
            if (!$sessionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No session ID provided'
                ], 400);
            }
            
            // Get previous context from cache
            $previousContext = $this->getPreviousContext($sessionId);
            if (!$previousContext) {
                return response()->json([
                    'success' => false,
                    'message' => 'No previous order found for this session'
                ], 404);
            }
            
            // Update log data
            $logData['input'] = $previousContext['transcription'] ?? '';
            $logData['request_content'] = $previousContext['transcription'] ?? '';
            $logData['session_id'] = $sessionId;
            $logData['metadata']['previous_context'] = $previousContext;
            
            // Process with the previous order data
            $user = Auth::check() ? Auth::user() : null;
            $recommendations = $this->foodIntelligenceService->filterProducts($previousContext['orderData']);
            $recommendationText = $this->aiOrderService->generateRecommendation($previousContext['orderData']);
            
            // Update log data with results
            $logData['output'] = $recommendationText;
            $logData['response_content'] = $recommendationText;
            $logData['product_ids'] = $recommendations->pluck('id')->toArray();
            $logData['filters_detected'] = $previousContext['orderData'];
            $logData['metadata']['products'] = $recommendations->pluck('id')->toArray();
            $success = true;
            
            // Calculate processing time and update success
            $logData['processing_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $logData['successful'] = $success;
            $logEntry = AIAssistantLog::create($logData);
            
            return response()->json([
                'success' => true, 
                'transcription' => $previousContext['transcription'],
                'intent_data' => $previousContext['orderData'],
                'recommendations' => $recommendations,
                'recommendation_text' => $recommendationText,
                'session_id' => $sessionId,
                'repeated' => true,
                'log_id' => $logEntry->id
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error repeating voice order: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            // Calculate processing time
            $logData['processing_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $logData['successful'] = false;
            $logEntry = AIAssistantLog::create($logData);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to repeat voice order: ' . $e->getMessage(),
                'log_id' => $logEntry->id
            ], 500);
        }
    }
    
    /**
     * Get voice order history for the current user
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrderHistory()
    {
        try {
            if (!Auth::check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required',
                    'history' => []
                ], 401);
            }
            
            $userId = Auth::id();
            $history = AIAssistantLog::where('user_id', $userId)
                ->where('request_type', 'voice_order')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function($log) {
                    return [
                        'id' => $log->id,
                        'transcription' => $log->input ?? '',
                        'session_id' => $log->session_id,
                        'successful' => $log->successful,
                        'date' => $log->created_at->toDateTimeString(),
                        'feedback' => $log->feedback,
                        'metadata' => $log->metadata
                    ];
                });
            
            return response()->json([
                'success' => true,
                'history' => $history
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching voice order history: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch voice order history: ' . $e->getMessage(),
                'history' => []
            ], 500);
        }
    }

    /**
     * Test OpenAI API key validity
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testOpenAIKey(Request $request)
    {
        $request->validate([
            'api_key' => 'nullable|string',
        ]);

        try {
            // Use provided key or fallback to configured key
            $apiKey = $request->input('api_key') ?: config('services.openai.api_key');
            
            if (empty($apiKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No API key provided',
                    'valid' => false
                ]);
            }

            // Check if API key has the correct format
            if (!preg_match('/^(sk-|sk-org-)/', $apiKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid API key format. OpenAI keys should start with "sk-" or "sk-org-"',
                    'valid' => false,
                    'error_type' => 'invalid_format'
                ]);
            }

            // Create a test instance of the OpenAI client
            $openAi = new \Orhanerday\OpenAi\OpenAi($apiKey);
            
            // Use chat completion API for testing
            $response = $openAi->chat([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant.'
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Say hello in one word'
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 10
            ]);
            
            $decoded = json_decode($response, true);
            
            // Check if we have a successful response
            if (isset($decoded['choices']) && is_array($decoded['choices']) && count($decoded['choices']) > 0) {
                $model = $decoded['model'] ?? 'unknown';
                return response()->json([
                    'success' => true,
                    'message' => 'API key is valid',
                    'valid' => true,
                    'model' => $model,
                    'response_sample' => $decoded['choices'][0]['message']['content'] ?? ''
                ]);
            } else {
                // Handle specific error cases with more helpful messages
                $errorType = $decoded['error']['type'] ?? '';
                $errorMessage = $decoded['error']['message'] ?? 'Unknown error';
                
                if (strpos($errorMessage, 'exceeded your current quota') !== false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'API key validation failed: ' . $errorMessage,
                        'valid' => false,
                        'error_type' => 'quota_exceeded',
                        'response' => $decoded
                    ]);
                } else if (strpos($errorMessage, 'Incorrect API key provided') !== false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'API key validation failed: ' . $errorMessage,
                        'valid' => false,
                        'error_type' => 'invalid_key',
                        'response' => $decoded
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'API key validation failed: ' . $errorMessage,
                        'valid' => false,
                        'error_type' => $errorType,
                        'response' => $decoded
                    ]);
                }
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error testing OpenAI API key: ' . $e->getMessage(),
                'valid' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 