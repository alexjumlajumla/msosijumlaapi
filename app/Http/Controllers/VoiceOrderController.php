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
            'session_id' => $sessionId
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
            $logData['filters_detected'] = $orderData;

            // Get product recommendations based on order data
            $recommendations = $this->foodIntelligenceService->filterProducts($orderData);
            
            // Generate a recommendation explanation
            $recommendationText = $this->aiOrderService->generateRecommendation($orderData);
            
            // Update log data with recommendations
            $logData['product_ids'] = $recommendations->pluck('id')->toArray();
            
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
            Log::error('Error processing voice order: ' . $e->getMessage());
            $logData['output'] = $e->getMessage();
            return response()->json([
                'success' => false, 
                'message' => 'Failed to process voice order.',
                'error' => $e->getMessage()
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
        
        $credits = $user->ai_order_credits ?? 3;
        if ($credits > 0) {
            $user->ai_order_credits = $credits - 1;
            $user->save();
        }
    }
} 