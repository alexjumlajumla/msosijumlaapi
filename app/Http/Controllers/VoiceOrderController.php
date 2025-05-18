<?php

namespace App\Http\Controllers;

use App\Services\VoiceOrderService;
use App\Services\AIOrderService;
use App\Services\FoodIntelligenceService;
use App\Models\User;
use App\Models\AIAssistantLog;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Exception;
use Illuminate\Support\Facades\Storage;
use App\Models\VoiceOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

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
        
        // Log the entire request for debugging
        \Log::info('Voice order request:', [
            'request' => $request->all(),
            'files' => $request->hasFile('audio') ? 'Audio file present' : 'No audio file',
            'headers' => $request->header(),
            'session_id' => $sessionId
        ]);
        
        $logData = [
            'user_id' => $userId,
            'request_type' => 'voice_order',
            'successful' => false,
            'session_id' => $sessionId,
            'metadata' => [
                'request_time' => now()->toIso8601String(),
                'client_ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ],
            'audio_stored' => false
        ];

        try {
            // Check if user has enough credits
            if (Auth::check()) {
                $user = Auth::user();
                if (!$this->checkVoiceOrderCredits($user)) {
                    \Log::warning('User does not have enough credits', ['user_id' => $userId]);
                    return response()->json([
                        'success' => false, 
                        'message' => 'You have used all your voice order credits. Please upgrade to continue.',
                        'upgrade_required' => true
                    ], 403);
                }
            }

            // Validate audio file exists
            if (!$request->hasFile('audio')) {
                \Log::error('No audio file provided in the request');
                return response()->json([
                    'success' => false,
                    'message' => 'No audio file provided',
                ], 422);
            }

            $audioFile = $request->file('audio');
            $audioFilePath = $audioFile->getPathname();
            $language = $request->input('language', 'en-US');
            
            // Track the transcription start time
            $transcriptionStartTime = microtime(true);
            
            // Save audio file to S3
            $audioUrl = $this->saveAudioFileToS3($audioFile, $sessionId);
            
            if ($audioUrl) {
                $logData['audio_url'] = $audioUrl;
                $logData['audio_format'] = $audioFile->getClientOriginalExtension();
                $logData['audio_stored'] = true;
                $logData['metadata']['audio_size'] = $audioFile->getSize();
                $logData['metadata']['audio_mime'] = $audioFile->getMimeType();
            }
            
            // Log audio file details
            \Log::info('Audio file details', [
                'size' => filesize($audioFilePath),
                'mime' => $audioFile->getMimeType(),
                'extension' => $audioFile->extension(),
                'language' => $language,
                'stored_in_s3' => !empty($audioUrl)
            ]);
            
            // Check for cached transcription with same audio hash
            $audioHash = md5_file($audioFilePath);
            $cachedTranscription = Cache::get('voice_transcription_' . $audioHash);
            
            if ($cachedTranscription) {
                \Log::info('Using cached transcription', ['hash' => $audioHash]);
                $transcription = $cachedTranscription;
                $logData['metadata']['cached'] = true;
            } else {
                // Transcribe the audio with language preference
                \Log::info('Transcribing audio', ['language' => $language]);
                $transcription = $this->voiceOrderService->transcribeAudio($audioFilePath, $language);
                
                // Cache the transcription for 2 minutes
                Cache::put('voice_transcription_' . $audioHash, $transcription, 120);
                $logData['metadata']['cached'] = false;
            }
            
            // Record transcription duration
            $transcriptionDuration = (int)((microtime(true) - $transcriptionStartTime) * 1000);
            $logData['metadata']['transcription_duration_ms'] = $transcriptionDuration;
            
            // Process the transcript with user context if available
            $user = Auth::check() ? Auth::user() : null;
            
            // Ensure transcription is a string - fixes the "Argument #1 ($transcription) must be of type string" error
            $transcriptionText = is_array($transcription) ? 
                ($transcription['transcription'] ?? '') : 
                (is_string($transcription) ? $transcription : '');
                
            \Log::info('Transcription result', ['text' => $transcriptionText]);
            
            // Validate transcription text
            if (empty($transcriptionText)) {
                \Log::warning('Empty transcription text from audio');
                return response()->json([
                    'success' => false,
                    'message' => 'No voice text could be extracted from the audio.',
                ], 422);
            }
                
            // Store the transcription in logData
            $logData['input'] = $transcriptionText;
            $logData['request_content'] = $transcriptionText;
            
            // Track AI processing time
            $aiStartTime = microtime(true);
                
            // Fix: Pass transcription as string to processOrderIntent
            \Log::info('Processing order intent', ['text' => $transcriptionText]);
            $orderData = $this->aiOrderService->processOrderIntent($transcriptionText, $user);
            \Log::info('Order intent processed', ['data' => $orderData]);
            
            // Get conversation context if this is a follow-up
            $previousContext = $this->getPreviousContext($sessionId);
            if ($previousContext && !empty($previousContext['orderData'])) {
                \Log::info('Merging with previous context', ['session_id' => $sessionId]);
                $orderData = $this->mergeWithPreviousContext($orderData, $previousContext['orderData']);
            }

            // Update log data with input and filters
            $logData['filters_detected'] = $orderData;
            $logData['metadata']['order_data'] = $orderData;

            // Get product recommendations based on order data
            \Log::info('Getting product recommendations', ['filters' => $orderData]);
            $recommendations = $this->foodIntelligenceService->filterProducts($orderData, $user);
            \Log::info('Recommendations retrieved', ['count' => $recommendations->count()]);
            
            // Generate a recommendation explanation
            $recommendationText = $this->aiOrderService->generateRecommendation($orderData);
            \Log::info('Generated recommendation text', ['text' => $recommendationText]);
            
            // Record AI processing duration
            $aiProcessingDuration = (int)((microtime(true) - $aiStartTime) * 1000);
            $logData['metadata']['ai_processing_duration_ms'] = $aiProcessingDuration;
            
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
            
            // Calculate the average score from recommendations if available
            $avgScore = 0;
            if ($recommendations->isNotEmpty() && $recommendations->first()->score) {
                $avgScore = $recommendations->avg('score');
            }
            
            // Create a VoiceOrder record
            $voiceOrderData = [
                'session_id' => $sessionId,
                'transcription' => $transcriptionText,
                'intent_data' => $orderData,
                'filters_detected' => $orderData,
                'product_ids' => $recommendations->pluck('id')->toArray(),
                'recommendation_text' => $recommendationText,
                'audio_url' => $logData['audio_url'] ?? null,
                'audio_format' => $logData['audio_format'] ?? null,
                'audio_file_path' => $audioFilePath,
                'confidence' => $transcription['confidence'] ?? null,
                'processing_time_ms' => $logData['processing_time_ms'],
                'transcription_duration_ms' => $transcriptionDuration,
                'ai_processing_duration_ms' => $aiProcessingDuration,
                'score' => $avgScore,
                'log_id' => $logEntry->id
            ];
            
            $voiceOrder = $this->voiceOrderService->createVoiceOrder($voiceOrderData, $user);
            
            $response = [
                'success' => true, 
                'transcription' => $transcriptionText,
                'intent_data' => $orderData,
                'recommendations' => $recommendations,
                'recommendation_text' => $recommendationText,
                'session_id' => $sessionId,
                'log_id' => $logEntry->id,
                'voice_order_id' => $voiceOrder->id,
                'confidence_score' => $transcription['confidence'] ?? null
            ];
            
            \Log::info('Voice order processed successfully', [
                'session_id' => $sessionId,
                'log_id' => $logEntry->id,
                'voice_order_id' => $voiceOrder->id,
                'processing_time_ms' => $logData['processing_time_ms']
            ]);
            
            return response()->json($response);
        } catch (\Exception $e) {
            \Log::error('Error processing voice order: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $logData['output'] = $e->getMessage();
            $logData['response_content'] = $e->getMessage();
            
            // More detailed error for debugging
            return response()->json([
                'success' => false,
                'message' => 'Error processing voice order: ' . $e->getMessage(),
                'error_type' => get_class($e)
            ], 500);
        } finally {
            // Always create log entry even on error
            if (!$success) {
                AIAssistantLog::create($logData);
            }
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
     * Simple endpoint to test API connectivity
     */
    public function testTranscribe(Request $request)
    {
        try {
            // Return a simple success response to confirm API connectivity
            return response()->json([
                'success' => true,
                'message' => 'Voice API connectivity test successful',
                'text' => 'Test transcription successful',
                'timestamp' => now()->toIso8601String(),
                'server_info' => [
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                    'environment' => app()->environment(),
                ]
            ]);
        } catch (\Exception $e) {
            // Log the error
            Log::error('Error in test transcribe endpoint: ' . $e->getMessage());
            
            // Return error response
            return response()->json([
                'success' => false,
                'message' => 'Test transcription failed: ' . $e->getMessage(),
                'timestamp' => now()->toIso8601String()
            ], 500);
        }
    }
    
    /**
     * Test OpenAI API key
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

    /**
     * Get a specific voice order log by ID
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVoiceLog($id)
    {
        try {
            $log = AIAssistantLog::with(['user'])->find($id);

            if (!$log) {
                return response()->json([
                    'status' => false,
                    'statusCode' => 'ERROR_404',
                    'message' => __('errors.ERROR_404')
                ], 404);
            }

            // Include recommendations if available
            if (!empty($log->product_ids) && is_array($log->product_ids)) {
                $products = Product::whereIn('id', $log->product_ids)
                    ->with(['translation', 'stocks'])
                    ->get();
                $log->recommendations = $products;
            }

            return response()->json([
                'status' => true,
                'message' => 'Success',
                'data' => $log
            ]);
        } catch (Exception $e) {
            Log::error('Error retrieving voice log', [
                'log_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'status' => false,
                'statusCode' => 'SERVER_ERROR',
                'message' => __('errors.SERVER_ERROR')
            ], 500);
        }
    }

    /**
     * Transcribe audio file only without processing order intent
     * This endpoint is specifically for the frontend to transcribe audio files
     * and is publicly accessible (no auth required)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function transcribe(Request $request)
    {
        try {
            // Log authentication information for debugging
            \Log::info('Public Transcription request', [
                'auth_header' => $request->header('Authorization'),
                'has_file' => $request->hasFile('audio'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);
            
            // Validate audio file exists
            if (!$request->hasFile('audio')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No audio file provided',
                ], 422);
            }

            $audioFile = $request->file('audio');
            $language = $request->input('language', 'en-US');
            $sessionId = $request->input('session_id', md5(uniqid()));
            
            // Log the audio details for debugging
            \Log::info('Audio file details', [
                'size' => $audioFile->getSize(),
                'mime' => $audioFile->getMimeType(),
                'extension' => $audioFile->extension(),
                'language' => $language
            ]);
            
            // Save audio to S3 if authenticated
            $audioUrl = null;
            if (Auth::check()) {
                $audioUrl = $this->saveAudioFileToS3($audioFile, $sessionId);
            }
            
            // Just transcribe the audio without further processing
            $result = $this->transcribeAudio($audioFile, $language);
            
            // Create log entry if authenticated
            if (Auth::check()) {
                $logData = [
                    'user_id' => Auth::id(),
                    'request_type' => 'transcription_only',
                    'session_id' => $sessionId,
                    'input' => $result['transcription'] ?? '',
                    'request_content' => $result['transcription'] ?? '',
                    'output' => $result['transcription'] ?? '',
                    'response_content' => $result['transcription'] ?? '',
                    'successful' => isset($result['success']) ? $result['success'] : false,
                    'processing_time_ms' => 0,
                    'metadata' => [
                        'language' => $language,
                        'confidence' => $result['confidence'] ?? 0,
                    ],
                    'audio_url' => $audioUrl,
                    'audio_format' => $audioFile->getClientOriginalExtension(),
                    'audio_stored' => !empty($audioUrl)
                ];
                
                $logEntry = AIAssistantLog::create($logData);
            }
            
            // Return the transcription result
            return response()->json([
                'success' => true,
                'text' => $result['transcription'] ?? '',
                'language' => $language,
                'confidence' => $result['confidence'] ?? 0,
                'provider' => 'google_speech',
                'audio_stored' => !empty($audioUrl),
                'log_id' => $logEntry->id ?? null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Transcription error in controller', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'text' => '',
                'language' => $language ?? 'en-US'
            ], 500);
        }
    }

    /**
     * Save audio file to S3 storage
     * 
     * @param UploadedFile $audioFile The uploaded audio file
     * @param string $sessionId Session identifier for the voice order
     * @return string|null S3 URL of the stored file or null if saving failed
     */
    private function saveAudioFileToS3($audioFile, string $sessionId): ?string
    {
        try {
            // Generate a unique filename with timestamp and session ID
            $fileName = 'voice-orders/' . auth()->id() . '/' . date('Y-m-d') . '/' . 
                        $sessionId . '-' . time() . '.' . $audioFile->getClientOriginalExtension();
            
            // Store the file in S3 with public visibility
            $path = Storage::disk('s3')->putFileAs(
                'voice-orders', 
                $audioFile, 
                $fileName, 
                'public'
            );
            
            if (!$path) {
                \Log::error('Failed to upload audio file to S3', [
                    'session_id' => $sessionId,
                    'original_name' => $audioFile->getClientOriginalName()
                ]);
                return null;
            }
            
            // Get the full S3 URL
            $url = Storage::disk('s3')->url($path);
            
            \Log::info('Audio file saved to S3', [
                'session_id' => $sessionId,
                'url' => $url,
                'path' => $path
            ]);
            
            return $url;
        } catch (\Exception $e) {
            \Log::error('Error saving audio file to S3: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'session_id' => $sessionId,
                'original_name' => $audioFile->getClientOriginalName(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Mark a voice order as fulfilled
     * 
     * @param int $id Voice order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsFulfilled($id)
    {
        // Admin permissions check
        if (!Auth::user()->can('manage_orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $voiceOrder = $this->voiceOrderService->markAsFulfilled($id);
        
        if (!$voiceOrder) {
            return response()->json(['message' => 'Voice order not found'], 404);
        }
        
        return response()->json([
            'message' => 'Voice order marked as fulfilled',
            'voice_order' => $voiceOrder
        ]);
    }
    
    /**
     * Assign an agent to a voice order
     * 
     * @param int $id Voice order ID
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignAgent($id, Request $request)
    {
        // Admin permissions check
        if (!Auth::user()->can('manage_orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'agent_id' => 'required|exists:users,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $voiceOrder = $this->voiceOrderService->assignAgent($id, $request->input('agent_id'));
        
        if (!$voiceOrder) {
            return response()->json(['message' => 'Voice order not found'], 404);
        }
        
        return response()->json([
            'message' => 'Agent assigned successfully',
            'voice_order' => $voiceOrder
        ]);
    }
    
    /**
     * Retry processing a voice order
     * 
     * @param int $id Voice order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function retryProcessing($id)
    {
        // Check permissions
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        
        // Only allow the user who created the voice order or admins
        $voiceOrder = VoiceOrder::find($id);
        if (!$voiceOrder) {
            return response()->json(['message' => 'Voice order not found'], 404);
        }
        
        if ($voiceOrder->user_id != Auth::id() && !Auth::user()->can('manage_orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $updatedVoiceOrder = $this->voiceOrderService->retryProcessing(
            $id, 
            $this->aiOrderService, 
            $this->foodIntelligenceService
        );
        
        if (!$updatedVoiceOrder) {
            return response()->json([
                'message' => 'Failed to reprocess voice order'
            ], 500);
        }
        
        // Get product details for response
        $productIds = $updatedVoiceOrder->product_ids ?? [];
        $recommendations = [];
        
        if (!empty($productIds)) {
            $recommendations = Product::whereIn('id', $productIds)
                ->with(['translation', 'stocks'])
                ->get();
        }
        
        return response()->json([
            'message' => 'Voice order reprocessed successfully',
            'voice_order' => $updatedVoiceOrder,
            'recommendations' => $recommendations
        ]);
    }
    
    /**
     * Get voice order statistics
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats(Request $request)
    {
        // Admin permissions check
        if (!Auth::user()->can('view_reports')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $period = $request->input('period', 'all');
        $stats = $this->voiceOrderService->getStats($period);
        
        return response()->json($stats);
    }
    
    /**
     * Get voice orders for a specific user
     * 
     * @param int $userId User ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserVoiceOrders($userId)
    {
        // Admin permissions check or self-access
        if (Auth::id() != $userId && !Auth::user()->can('manage_orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $voiceOrders = $this->voiceOrderService->getUserVoiceOrders($userId);
        
        return response()->json(['voice_orders' => $voiceOrders]);
    }
    
    /**
     * Link a voice order to a regular order
     * 
     * @param int $voiceOrderId Voice order ID
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function linkToOrder($voiceOrderId, Request $request)
    {
        // Validate
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $voiceOrder = $this->voiceOrderService->linkToOrder($voiceOrderId, $request->input('order_id'));
        
        if (!$voiceOrder) {
            return response()->json(['message' => 'Voice order not found'], 404);
        }
        
        return response()->json([
            'message' => 'Voice order linked to order successfully',
            'voice_order' => $voiceOrder
        ]);
    }
} 