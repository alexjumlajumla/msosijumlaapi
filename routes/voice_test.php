<?php

use App\Http\Controllers\VoiceOrderController;
use Illuminate\Support\Facades\Route;

/**
 * Voice Order System - Test API Endpoint
 * 
 * This standalone endpoint provides a simple way to test the voice order functionality
 * without requiring authentication. It performs both speech-to-text transcription
 * and intent analysis in a single request.
 * 
 * Used by: public/voice-test/index.html
 */
Route::post('/api/voice-test-api', function() {
    try {
        $request = request();
        $audioFile = $request->file('audio');
        $language = $request->input('language', 'en-US');
        
        if (!$audioFile) {
            return response()->json([
                'success' => false,
                'message' => 'No audio file provided'
            ], 400);
        }
        
        // Step 1: Process audio with Google Speech-to-Text
        $voiceController = app(VoiceOrderController::class);
        $transcriptionResult = $voiceController->transcribeAudio($audioFile, $language);
        
        if (!isset($transcriptionResult['text']) || empty($transcriptionResult['text'])) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to transcribe audio',
                'transcription_error' => $transcriptionResult['error'] ?? 'Unknown error'
            ], 500);
        }
        
        // Step 2: Process with OpenAI for intent understanding
        try {
            $apiKey = config('services.openai.api_key');
            $openAi = new Orhanerday\OpenAi\OpenAi($apiKey);
            
            $response = $openAi->chat([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant for a food ordering system. Extract key food items, quantities, and special instructions from the user\'s voice transcription.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $transcriptionResult['text'],
                    ],
                ],
                'temperature' => 0.7,
                'max_tokens' => 150,
            ]);
            
            $openAiResult = json_decode($response, true);
            
            return response()->json([
                'success' => true,
                'transcription' => $transcriptionResult['text'],
                'openai_analysis' => $openAiResult,
                'language' => $language
            ]);
        } catch (\Exception $openAiError) {
            return response()->json([
                'success' => false,
                'message' => 'OpenAI processing failed',
                'transcription' => $transcriptionResult['text'],
                'error' => $openAiError->getMessage()
            ], 500);
        }
        
    } catch (\Exception $e) {
        \Log::error('Voice test failed: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Voice test failed: ' . $e->getMessage(),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    }
});
