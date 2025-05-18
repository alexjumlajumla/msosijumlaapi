<?php

namespace App\Services;

use Orhanerday\OpenAi\OpenAi;
use App\Models\User;
use App\Models\AIAssistantLog;
use App\Models\OrderDetail;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AIOrderService
{
    protected OpenAi $openAi;
    protected bool $apiInitialized = false;

    public function __construct()
    {
        $apiKey = config('services.openai.api_key');
        
        if (!empty($apiKey)) {
            try {
                $this->openAi = new OpenAi($apiKey);
                $this->apiInitialized = true;
                Log::info('OpenAI client initialized successfully with API key: ' . substr($apiKey, 0, 5) . '...');
            } catch (\Exception $e) {
                Log::error('Failed to initialize OpenAI client: ' . $e->getMessage(), [
                    'exception' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                    'api_key_length' => strlen($apiKey)
                ]);
                $this->apiInitialized = false;
            }
        } else {
            Log::error('OpenAI API key is empty or not set in configuration', [
                'api_key_from_env' => !empty(env('OPENAI_API_KEY')) ? 'Set (length: ' . strlen(env('OPENAI_API_KEY')) . ')' : 'Not set',
                'api_key_from_config' => 'Not set',
                'services_config_exists' => !empty(config('services')) ? 'Yes' : 'No',
                'openai_config_exists' => !empty(config('services.openai')) ? 'Yes' : 'No'
            ]);
            $this->apiInitialized = false;
        }
    }

    public function processOrderIntent(string $transcription, ?User $user = null): array
    {
        if (!$this->apiInitialized) {
            Log::warning('Attempted to process order intent but OpenAI API is not initialized');
            return [
                'error' => 'OpenAI API is not properly configured',
                'intent' => 'Unknown'
            ];
        }
        
        // Get user order history for personalization if user is authenticated
        $userContext = '';
        if ($user) {
            $userContext = $this->getUserOrderContext($user);
        }

        // Create a system message and user message for chat completion
        $systemMessage = "You are a food ordering assistant that specializes in understanding customer preferences and dietary requirements. Extract structured information from voice transcripts.";
        
        $userMessage = "Extract information from the following food order request: \"$transcription\"\n\n";
        $userMessage .= $userContext . "\n\n";
        $userMessage .= "Return a JSON object with the following structure:
{
  \"intent\": \"The main food or dish the user wants\",
  \"filters\": [\"List of dietary preferences like vegetarian, vegan, gluten-free, etc.\"],
  \"cuisine_type\": \"The type of cuisine if mentioned (e.g., Italian, Chinese, etc.)\",
  \"exclusions\": [\"Ingredients the user wants to avoid\"],
  \"portion_size\": \"Regular, Large, etc. if specified\",
  \"spice_level\": \"Mild, Medium, Hot if specified\"
}

Focus on identifying specific food preferences and requirements. Infer reasonable values if some fields are uncertain.";
        
        try {
            // Use chat completion with the new API
            $response = $this->openAi->chat([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemMessage
                    ],
                    [
                        'role' => 'user',
                        'content' => $userMessage
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 500,
            ]);
            
            $decoded = json_decode($response, true);
            
            // Check for API errors
            if (isset($decoded['error'])) {
                $errorMessage = $decoded['error']['message'] ?? 'Unknown OpenAI API error';
                Log::error('OpenAI API error during processOrderIntent: ' . $errorMessage);
                
                return [
                    'error' => $errorMessage,
                    'intent' => 'Unknown'
                ];
            }
            
            // Extract content from the chat completion response
            $content = $decoded['choices'][0]['message']['content'] ?? '';
    
            // Try to extract JSON from the response
            try {
                $jsonStart = strpos($content, '{');
                $jsonEnd = strrpos($content, '}');
                if ($jsonStart !== false && $jsonEnd !== false) {
                    $jsonContent = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
                    $orderData = json_decode($jsonContent, true) ?? [];
                } else {
                    $orderData = json_decode($content, true) ?? [];
                }
            } catch (\Exception $e) {
                Log::error('Error parsing JSON from OpenAI response: ' . $e->getMessage());
                $orderData = [];
            }
            
            // Add additional processing to handle ambiguities and alternatives
            $orderData = $this->enhanceOrderData($orderData, $transcription);
            
            return $orderData;
        } catch (\Exception $e) {
            Log::error('Exception during OpenAI processOrderIntent: ' . $e->getMessage());
            return [
                'error' => 'Failed to process order: ' . $e->getMessage(),
                'intent' => 'Unknown'
            ];
        }
    }
    
    /**
     * Get user order history context for personalization
     */
    private function getUserOrderContext(User $user): string
    {
        // Get user's recent order items
        $recentOrders = OrderDetail::join('orders', 'order_details.order_id', '=', 'orders.id')
            ->join('products', 'order_details.product_id', '=', 'products.id')
            ->join('product_translations', function($join) {
                $join->on('products.id', '=', 'product_translations.product_id')
                    ->where('product_translations.locale', '=', app()->getLocale());
            })
            ->where('orders.user_id', $user->id)
            ->where('orders.created_at', '>=', now()->subMonths(3))
            ->select('product_translations.title', DB::raw('COUNT(*) as order_count'))
            ->groupBy('product_translations.title')
            ->orderBy('order_count', 'desc')
            ->limit(5)
            ->get();
            
        if ($recentOrders->isEmpty()) {
            return '';
        }
        
        $favoriteItems = $recentOrders->pluck('title')->implode(', ');
        
        return "This user frequently orders: $favoriteItems. Consider these preferences when interpreting their request.";
    }
    
    /**
     * Enhance order data with additional processing
     */
    private function enhanceOrderData(array $orderData, string $transcription): array
    {
        // Add cuisine type if not detected
        if (empty($orderData['cuisine_type'])) {
            $orderData['cuisine_type'] = $this->detectCuisineType($transcription);
        }
        
        // Ensure filters is an array
        if (!isset($orderData['filters']) || !is_array($orderData['filters'])) {
            $orderData['filters'] = [];
        }
        
        // Ensure exclusions is an array
        if (!isset($orderData['exclusions']) || !is_array($orderData['exclusions'])) {
            $orderData['exclusions'] = [];
        }
        
        return $orderData;
    }
    
    /**
     * Detect cuisine type from transcription using keywords
     */
    private function detectCuisineType(string $transcription): string
    {
        $cuisineKeywords = [
            'italian' => ['pasta', 'pizza', 'italian', 'lasagna', 'risotto'],
            'chinese' => ['chinese', 'fried rice', 'noodles', 'dim sum', 'wonton'],
            'indian' => ['curry', 'indian', 'masala', 'biryani', 'tandoori'],
            'thai' => ['thai', 'pad thai', 'curry', 'tom yum', 'satay'],
            'mexican' => ['mexican', 'taco', 'burrito', 'quesadilla', 'enchilada'],
            'japanese' => ['sushi', 'japanese', 'ramen', 'tempura', 'teriyaki'],
        ];
        
        $lowerTranscription = strtolower($transcription);
        
        foreach ($cuisineKeywords as $cuisine => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($lowerTranscription, $keyword) !== false) {
                    return $cuisine;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Generate a food recommendation based on input and preferences
     */
    public function generateRecommendation(array $orderData): string
    {
        if (!$this->apiInitialized) {
            Log::warning('Attempted to generate recommendation but OpenAI API is not initialized');
            return 'Unable to generate recommendation due to API configuration issues.';
        }
        
        try {
            // Use chat completion with the new API
            $response = $this->openAi->chat([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful food recommendation assistant.'
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Generate a personalized food recommendation based on these preferences: ' . json_encode($orderData)
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 150,
            ]);
            
            $decoded = json_decode($response, true);
            
            // Check for API errors
            if (isset($decoded['error'])) {
                $errorMessage = $decoded['error']['message'] ?? 'Unknown OpenAI API error';
                Log::error('OpenAI API error during generateRecommendation: ' . $errorMessage);
                return 'Unable to generate recommendation: ' . $errorMessage;
            }
            
            return $decoded['choices'][0]['message']['content'] ?? 'No recommendation available';
        } catch (\Exception $e) {
            Log::error('Exception during OpenAI generateRecommendation: ' . $e->getMessage());
            return 'Unable to generate recommendation due to an error.';
        }
    }
} 