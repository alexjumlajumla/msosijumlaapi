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
        $logData = [
            'user_id' => $userId,
            'request_type' => 'voice_order',
            'successful' => false
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
            $transcription = $this->voiceOrderService->transcribeAudio($audioFilePath);
            $orderData = $this->aiOrderService->processOrderIntent($transcription);

            // Update log data with input
            $logData['input'] = $transcription;
            $logData['filters_detected'] = $orderData;

            // Get product recommendations based on order data
            $recommendations = $this->foodIntelligenceService->filterProducts($orderData);
            
            // Update log data with recommendations
            $logData['product_ids'] = $recommendations->pluck('id')->toArray();

            // Decrement user's voice order credits if authenticated
            if (Auth::check()) {
                $this->decrementVoiceOrderCredits(Auth::user());
            }

            $success = true;
            return response()->json([
                'success' => true, 
                'transcription' => $transcription,
                'intent_data' => $orderData,
                'recommendations' => $recommendations
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing voice order: ' . $e->getMessage());
            $logData['output'] = $e->getMessage();
            return response()->json(['success' => false, 'message' => 'Failed to process voice order.'], 500);
        } finally {
            // Calculate processing time
            $processingTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            $logData['processing_time_ms'] = (int)$processingTime;
            $logData['successful'] = $success;
            
            // Log interaction
            AIAssistantLog::logInteraction($logData);
        }
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