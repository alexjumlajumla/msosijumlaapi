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
            
            // Transcribe the audio with language preference
            $transcription = $this->voiceOrderService->transcribeAudio($audioFilePath, $language);
            
            // Process the transcript with user context if available
            $user = Auth::check() ? Auth::user() : null;
            $orderData = $this->aiOrderService->processOrderIntent($transcription, $user);
            
            // Get conversation context if this is a follow-up
            $previousContext = $this->getPreviousContext($sessionId);
            if ($previousContext && !empty($previousContext['orderData'])) {
                $orderData = $this->mergeWithPreviousContext($orderData, $previousContext['orderData']);
            }

            // Update log data with input
            $logData['input'] = $transcription;
            $logData['request_content'] = $transcription;
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
            
            // Save context for follow-up questions
            $this->saveConversationContext($sessionId, [
                'transcription' => $transcription,
                'orderData' => $orderData,
                'timestamp' => time()
            ]);

            // Decrement user's voice order credits if authenticated
            if (Auth::check()) {
                $this->decrementVoiceOrderCredits(Auth::user());
            }

            $success = true;
            return response()->json([
                'success' => true, 
                'transcription' => $transcription,
                'intent_data' => $orderData,
                'recommendations' => $recommendations,
                'recommendation_text' => $recommendationText,
                'session_id' => $sessionId
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
            
            return response()->json([
                'success' => false, 
                'message' => 'Failed to process voice order. Error: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'error_details' => $errorDetails
            ], 500);
        } finally {
            // Calculate processing time
            $processingTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            $logData['processing_time_ms'] = (int)$processingTime;
            $logData['successful'] = $success;
            
            // Log interaction
            AIAssistantLog::create($logData);
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
                ]
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
     * @param \Illuminate\Http\UploadedFile $audioFile
     * @param string $language Language code, default en-US
     * @return array Transcription result with text and confidence
     */
    public function transcribeAudio($audioFile, string $language = 'en-US'): array
    {
        try {
            $filePath = $audioFile->getPathname();
            $text = $this->voiceOrderService->transcribeAudio($filePath, $language);
            
            return [
                'success' => true, 
                'text' => $text,
                'language' => $language
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'text' => ''
            ];
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
        try {
            $audioFile = $request->file('audio');
            $language = $request->input('language', 'en-US');
            
            if (!$audioFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'No audio file provided'
                ], 400);
            }
            
            // Just transcribe the audio without further processing
            $result = $this->transcribeAudio($audioFile, $language);
            
            return response()->json([
                'success' => true,
                'text' => $result['text'] ?? '',
                'language' => $language
            ]);
        } catch (\Exception $e) {
            \Log::error('Real-time transcription failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Transcription failed: ' . $e->getMessage(),
                'text' => ''
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
            
            // Process with the previous order data
            $user = Auth::check() ? Auth::user() : null;
            $recommendations = $this->foodIntelligenceService->filterProducts($previousContext['orderData']);
            $recommendationText = $this->aiOrderService->generateRecommendation($previousContext['orderData']);
            
            return response()->json([
                'success' => true, 
                'transcription' => $previousContext['transcription'],
                'intent_data' => $previousContext['orderData'],
                'recommendations' => $recommendations,
                'recommendation_text' => $recommendationText,
                'session_id' => $sessionId,
                'repeated' => true
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error repeating voice order: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to repeat voice order: ' . $e->getMessage()
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

            // Create a test instance of the OpenAI client
            $openAi = new \Orhanerday\OpenAi\OpenAi($apiKey);
            
            // Try to make a simple API call using the completion endpoint
            // This is more likely to work with older versions of the package
            $response = $openAi->completion([
                'model' => 'text-davinci-003',
                'prompt' => 'Say hello in one word',
                'temperature' => 0.7,
                'max_tokens' => 10,
                'frequency_penalty' => 0,
                'presence_penalty' => 0
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
                    'response_sample' => $decoded['choices'][0]['text'] ?? ''
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'API key validation failed: ' . ($decoded['error']['message'] ?? 'Unknown error'),
                    'valid' => false,
                    'response' => $decoded
                ]);
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