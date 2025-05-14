<?php

use App\Http\Controllers\VoiceOrderController;
use Illuminate\Support\Facades\Route;

// Standalone voice-test endpoint for frontend testing
Route::post('/voice-test', function() {
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
        
        // First process with Google Speech-to-Text
        $voiceController = app(VoiceOrderController::class);
        $transcriptionResult = $voiceController->transcribeAudio($audioFile, $language);
        
        if (!isset($transcriptionResult['text']) || empty($transcriptionResult['text'])) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to transcribe audio',
                'transcription_error' => $transcriptionResult['error'] ?? 'Unknown error'
            ], 500);
        }
        
        // Then process with OpenAI for understanding
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
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Voice test failed: ' . $e->getMessage(),
            'error' => $e->getMessage(),
            'trace' => explode("\n", $e->getTraceAsString())
        ], 500);
    }
});
