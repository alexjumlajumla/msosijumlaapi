<?php

namespace App\Services;

use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\SpeechClient;
use Google\Cloud\Speech\V1\SpeechContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class VoiceOrderService
{
    protected SpeechClient $speechClient;
    protected array $supportedLanguages;

    public function __construct()
    {
        $this->speechClient = new SpeechClient([
            'credentials' => config('services.google.credentials'),
        ]);
        
        // Define supported languages for the voice recognition system
        $this->supportedLanguages = [
            'en-US' => 'English (US)',
            'en-GB' => 'English (UK)',
            'ar-SA' => 'Arabic',
            'es-ES' => 'Spanish',
            'fr-FR' => 'French',
            'hi-IN' => 'Hindi',
            'sw-TZ' => 'Swahili',
            'zh-CN' => 'Chinese (Simplified)',
        ];
    }

    /**
     * Transcribe audio with support for multiple languages
     */
    public function transcribeAudio(string $audioFilePath, string $languageCode = 'en-US'): string
    {
        // Validate language code
        if (!array_key_exists($languageCode, $this->supportedLanguages)) {
            $languageCode = 'en-US'; // Default to English if invalid
        }
        
        try {
            // Read audio file contents
            $audioContent = file_get_contents($audioFilePath);
            
            $audio = (new RecognitionAudio())
                ->setContent($audioContent);
    
            // Create speech context with proper SpeechContext object
            $phrases = $this->getFoodRelatedPhrases();
            $speechContext = new SpeechContext();
            $speechContext->setPhrases($phrases);
            $speechContext->setBoost(20.0);
    
            // Configure the recognition settings
            $config = (new RecognitionConfig())
                ->setEncoding(RecognitionConfig\AudioEncoding::LINEAR16)
                ->setSampleRateHertz(16000)
                ->setLanguageCode($languageCode)
                ->setModel('command_and_search') // Optimized for short commands
                ->setUseEnhanced(true) // Use enhanced model
                ->setProfanityFilter(false) // Allow all words
                ->setEnableAutomaticPunctuation(true)
                ->setSpeechContexts([$speechContext]);
    
            $response = $this->speechClient->recognize($config, $audio);
    
            $transcription = '';
            foreach ($response->getResults() as $result) {
                $transcription .= $result->getAlternatives()[0]->getTranscript();
            }
    
            return $transcription;
        } catch (\Exception $e) {
            Log::error('Speech recognition error: ' . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Get a list of common food-related phrases to improve recognition
     */
    private function getFoodRelatedPhrases(): array
    {
        // Cache the phrases for better performance
        return Cache::remember('food_related_phrases', 86400, function() {
            return [
                // Common food items
                'pizza', 'burger', 'pasta', 'salad', 'sandwich', 'sushi',
                'rice', 'noodles', 'chicken', 'beef', 'fish', 'vegetarian',
                
                // Common dietary preferences
                'gluten-free', 'dairy-free', 'vegan', 'vegetarian', 'halal', 'kosher',
                'paleo', 'keto', 'low carb', 'sugar-free', 'organic',
                
                // Common allergens
                'peanuts', 'nuts', 'shellfish', 'dairy', 'gluten', 'soy', 'eggs',
                
                // Common cuisines
                'Italian', 'Chinese', 'Mexican', 'Indian', 'Thai', 'Japanese',
                'Mediterranean', 'American', 'Ethiopian', 'French', 'Greek',
                
                // Common cooking methods
                'grilled', 'fried', 'baked', 'roasted', 'steamed', 'raw',
                
                // Order-related phrases
                'I want', 'I would like', 'Can I have', 'Give me', 'Order',
                'without', 'extra', 'no', 'please', 'thanks', 'delivery',
            ];
        });
    }
    
    /**
     * Get list of supported languages
     */
    public function getSupportedLanguages(): array
    {
        return $this->supportedLanguages;
    }

    /**
     * Process non-standard audio formats before transcription
     */
    public function processAudioFile(string $filePath): string
    {
        // In a real implementation, we would use a library like FFmpeg to convert
        // audio files to the format required by Google Speech API
        // For now, we'll just return the original file path
        return $filePath;
    }

    public function __destruct()
    {
        $this->speechClient->close();
    }
} 