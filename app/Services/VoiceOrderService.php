<?php

namespace App\Services;

use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\SpeechClient;
use Google\Cloud\Speech\V1\SpeechContext;
use Google\Cloud\Speech\V1\StreamingRecognitionConfig;
use Google\Cloud\Speech\V1\StreamingRecognizeRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Exception;
use RuntimeException;

class VoiceOrderService
{
    /**
     * @var SpeechClient Google Cloud Speech client
     */
    protected SpeechClient $speechClient;
    
    /**
     * @var array Supported language codes and their display names
     */
    protected array $supportedLanguages;
    
    /**
     * @var array Custom user-defined phrases to improve recognition
     */
    protected array $customPhrases = [];
    
    /**
     * @var array Recognized audio formats
     */
    protected array $supportedFormats = [
        'webm' => RecognitionConfig\AudioEncoding::WEBM_OPUS,
        'wav' => RecognitionConfig\AudioEncoding::LINEAR16,
        'mp3' => RecognitionConfig\AudioEncoding::MP3,
        'flac' => RecognitionConfig\AudioEncoding::FLAC,
    ];

    /**
     * Constructor with optional dependency injection for testing
     */
    public function __construct(SpeechClient $client = null)
    {
        $this->speechClient = $client ?? new SpeechClient([
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
     * Enhanced with retry mechanism, confidence scores, and timestamps
     * 
     * @param string $audioFilePath Path to the audio file
     * @param string $languageCode Language code
     * @return array Transcription result with metadata
     */
    public function transcribeAudio(string $audioFilePath, string $languageCode = 'en-US'): array
    {
        // Validate file exists and is readable
        if (!file_exists($audioFilePath) || !is_readable($audioFilePath)) {
            Log::warning("Audio file not found or unreadable: {$audioFilePath}", [
                'path' => $audioFilePath
            ]);
            return [
                'transcription' => '',
                'confidence' => 0,
                'error' => 'File not found or unreadable'
            ];
        }
        
        // Validate language code
        if (!array_key_exists($languageCode, $this->supportedLanguages)) {
            Log::info("Unsupported language code provided: {$languageCode}, defaulting to en-US");
            $languageCode = 'en-US'; // Default to English if invalid
        }
        
        try {
            // Process audio format if needed
            $processedFilePath = $this->processAudioFile($audioFilePath);
            
            // Read audio file contents
            $audioContent = file_get_contents($processedFilePath);
            
            // Create audio object
            $audio = (new RecognitionAudio())
                ->setContent($audioContent);
    
            // Create speech context with proper SpeechContext object
            $allPhrases = array_merge($this->getFoodRelatedPhrases(), $this->customPhrases);
            $speechContext = new SpeechContext();
            $speechContext->setPhrases($allPhrases);
            $speechContext->setBoost(20.0);
    
            // Get file format and choose appropriate encoding
            $fileExtension = pathinfo($processedFilePath, PATHINFO_EXTENSION);
            $encoding = $this->getEncodingForFormat($fileExtension);
            
            // Configure the recognition settings
            $config = (new RecognitionConfig())
                ->setEncoding($encoding)
                ->setLanguageCode($languageCode)
                ->setModel('command_and_search') // Optimized for short commands
                ->setUseEnhanced(true) // Use enhanced model
                ->setProfanityFilter(false) // Allow all words
                ->setEnableAutomaticPunctuation(true)
                ->setEnableWordTimeOffsets(true) // Enable word timestamps
                ->setSpeechContexts([$speechContext]);
            
            // Use Laravel's retry helper to attempt recognition multiple times
            $response = retry(3, function() use ($config, $audio) {
                return $this->speechClient->recognize($config, $audio);
            }, 100); // 100ms delay between retries
            
            // Process and structure the results
            $transcription = '';
            $confidence = 0;
            $wordTimestamps = [];
            $alternatives = [];
            
            foreach ($response->getResults() as $result) {
                foreach ($result->getAlternatives() as $index => $alternative) {
                    // For the first/best alternative, append to the main transcription
                    if ($index === 0) {
                        $transcription .= $alternative->getTranscript() . ' ';
                        $confidence = max($confidence, $alternative->getConfidence());
                        
                        // Process word level information if available
                        foreach ($alternative->getWords() as $wordInfo) {
                            $startTime = $wordInfo->getStartTime()->getSeconds() + 
                                         $wordInfo->getStartTime()->getNanos() / 1e9;
                            $endTime = $wordInfo->getEndTime()->getSeconds() + 
                                       $wordInfo->getEndTime()->getNanos() / 1e9;
                                       
                            $wordTimestamps[] = [
                                'word' => $wordInfo->getWord(),
                                'start_time' => $startTime,
                                'end_time' => $endTime
                            ];
                        }
                    }
                    
                    // Store all alternatives
                    $alternatives[] = [
                        'transcript' => $alternative->getTranscript(),
                        'confidence' => $alternative->getConfidence()
                    ];
                }
            }
            
            // Clean up temporary processed file if different from original
            if ($processedFilePath !== $audioFilePath && file_exists($processedFilePath)) {
                @unlink($processedFilePath);
            }
    
            return [
                'transcription' => trim($transcription),
                'confidence' => $confidence,
                'word_timestamps' => $wordTimestamps,
                'alternatives' => $alternatives,
                'language' => $languageCode,
                'success' => true
            ];
        } catch (Exception $e) {
            // Enhanced error logging with context
            Log::error('Speech recognition error', [
                'file' => $audioFilePath,
                'language' => $languageCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'transcription' => '',
                'confidence' => 0,
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];
        }
    }
    
    /**
     * Streaming transcription for real-time voice processing
     * 
     * @param resource $audioStream Stream resource of audio data
     * @param string $languageCode Language code
     * @param callable $callback Function to call for each piece of transcription
     * @return void
     */
    public function streamTranscription($audioStream, string $languageCode = 'en-US', callable $callback = null): void
    {
        if (!array_key_exists($languageCode, $this->supportedLanguages)) {
            $languageCode = 'en-US';
        }
        
        try {
            // Create speech context
            $allPhrases = array_merge($this->getFoodRelatedPhrases(), $this->customPhrases);
            $speechContext = new SpeechContext();
            $speechContext->setPhrases($allPhrases);
            $speechContext->setBoost(20.0);
            
            // Configure recognition
            $config = (new RecognitionConfig())
                ->setEncoding(RecognitionConfig\AudioEncoding::LINEAR16)
                ->setSampleRateHertz(16000) // For streaming, a fixed rate is often required
                ->setLanguageCode($languageCode)
                ->setModel('command_and_search')
                ->setProfanityFilter(false)
                ->setEnableAutomaticPunctuation(true)
                ->setSpeechContexts([$speechContext]);
                
            // Create streaming config
            $streamingConfig = (new StreamingRecognitionConfig())
                ->setConfig($config)
                ->setInterimResults(true); // Show interim results
            
            // Start streaming recognition
            $stream = $this->speechClient->streamingRecognize();
            
            // Send streaming config first
            $request = new StreamingRecognizeRequest();
            $request->setStreamingConfig($streamingConfig);
            $stream->write($request);
            
            // Function to handle incoming responses
            $responseHandler = function($response) use ($callback) {
                foreach ($response->getResults() as $result) {
                    $alternative = $result->getAlternatives()[0];
                    $transcription = $alternative->getTranscript();
                    $isFinal = $result->getIsFinal();
                    $stability = $result->getStability();
                    
                    if ($callback) {
                        $callback([
                            'transcription' => $transcription,
                            'is_final' => $isFinal,
                            'stability' => $stability
                        ]);
                    }
                }
            };
            
            // Stream audio data in chunks
            $chunkSize = 8192; // Adjust based on your needs
            while (!feof($audioStream)) {
                $chunk = fread($audioStream, $chunkSize);
                if ($chunk) {
                    $request = new StreamingRecognizeRequest();
                    $request->setAudioContent($chunk);
                    $stream->write($request);
                    
                    // Process any available responses
                    foreach ($stream->responses() as $response) {
                        $responseHandler($response);
                    }
                }
            }
            
            // Close stream and process final responses
            $stream->writesDone();
            foreach ($stream->closeWriteAndReadAll() as $response) {
                $responseHandler($response);
            }
            
        } catch (Exception $e) {
            Log::error('Streaming recognition error', [
                'language' => $languageCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if ($callback) {
                $callback([
                    'transcription' => '',
                    'is_final' => true,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Set custom speech context phrases dynamically
     * 
     * @param array $phrases Array of phrases to improve recognition
     * @return self For method chaining
     */
    public function setCustomPhrases(array $phrases): self
    {
        $this->customPhrases = $phrases;
        return $this;
    }
    
    /**
     * Add custom phrases to the existing set
     * 
     * @param array $phrases Array of phrases to add
     * @return self For method chaining
     */
    public function addCustomPhrases(array $phrases): self
    {
        $this->customPhrases = array_merge($this->customPhrases, $phrases);
        return $this;
    }
    
    /**
     * Get a list of common food-related phrases to improve recognition
     * 
     * @return array Food-related phrases
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
     * Get RecognitionConfig AudioEncoding for a file format
     * 
     * @param string $format File extension or format string
     * @return int AudioEncoding value
     */
    private function getEncodingForFormat(string $format): int
    {
        $format = strtolower($format);
        
        // Check if the format is supported, default to WEBM_OPUS
        return $this->supportedFormats[$format] ?? RecognitionConfig\AudioEncoding::WEBM_OPUS;
    }
    
    /**
     * Get list of supported languages
     * 
     * @return array Supported language codes and names
     */
    public function getSupportedLanguages(): array
    {
        return $this->supportedLanguages;
    }

    /**
     * Process and convert audio file to an optimal format
     * Uses FFmpeg if available, otherwise returns the original file
     * 
     * @param string $filePath Original audio file path
     * @return string Path to processed file (may be the same as input)
     */
    public function processAudioFile(string $filePath): string
    {
        // Get file info
        $fileInfo = pathinfo($filePath);
        $extension = strtolower($fileInfo['extension'] ?? '');
        
        // Check if the format is already supported
        if (array_key_exists($extension, $this->supportedFormats)) {
            return $filePath;
        }
        
        // Define the output file
        $outputPath = storage_path('app/temp_audio_' . Str::random(8) . '.webm');
        
        // Check if FFmpeg is available
        $ffmpegPath = trim(shell_exec('which ffmpeg') ?: '');
        if (empty($ffmpegPath)) {
            Log::warning('FFmpeg not found. Audio conversion skipped.', [
                'input_file' => $filePath,
                'format' => $extension
            ]);
            return $filePath;
        }
        
        // Convert using FFmpeg (basic implementation)
        $command = "{$ffmpegPath} -i \"{$filePath}\" -c:a libopus -b:a 32k -vbr on \"{$outputPath}\" 2>&1";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            Log::warning('Audio conversion failed', [
                'input_file' => $filePath,
                'output_file' => $outputPath,
                'command' => $command,
                'output' => implode("\n", $output),
                'return_code' => $returnCode
            ]);
            return $filePath;
        }
        
        // Verify the output file exists
        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            Log::warning('Audio conversion output file is missing or empty', [
                'output_file' => $outputPath
            ]);
            return $filePath;
        }
        
        return $outputPath;
    }
    
    /**
     * Try to auto-detect the language of the audio content
     * This is a placeholder implementation that could be expanded
     * 
     * @param string $audioFilePath Path to audio file
     * @return string|null Detected language code or null if detection failed
     */
    public function detectLanguage(string $audioFilePath): ?string
    {
        // Implement language detection logic here
        // For now, just return null to indicate no detection
        return null;
    }

    /**
     * Clean up resources on destruction
     */
    public function __destruct()
    {
        $this->speechClient->close();
    }
} 