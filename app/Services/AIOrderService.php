<?php

namespace App\Services;

use Orhanerday\OpenAi\OpenAi;
use App\Models\User;
use App\Models\AIAssistantLog;
use App\Models\OrderDetail;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class AIOrderService
{
    protected OpenAi $openAi;

    public function __construct()
    {
        $this->openAi = new OpenAi(config('services.openai.api_key'));
    }

    public function processOrderIntent(string $transcription, ?User $user = null): array
    {
        // Get user order history for personalization if user is authenticated
        $userContext = '';
        if ($user) {
            $userContext = $this->getUserOrderContext($user);
        }

        $prompt = <<<EOT
Extract information from the following food order request: "$transcription"

$userContext

Return a JSON object with the following structure:
{
  "intent": "The main food or dish the user wants",
  "filters": ["List of dietary preferences like vegetarian, vegan, gluten-free, etc."],
  "cuisine_type": "The type of cuisine if mentioned (e.g., Italian, Chinese, etc.)",
  "exclusions": ["Ingredients the user wants to avoid"],
  "portion_size": "Regular, Large, etc. if specified",
  "spice_level": "Mild, Medium, Hot if specified"
}

Focus on identifying specific food preferences and requirements. Infer reasonable values if some fields are uncertain.
EOT;

        $response = $this->openAi->chat([
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a food ordering assistant that specializes in understanding customer preferences and dietary requirements. Extract structured information from voice transcripts.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.3,
            'max_tokens' => 250,
        ]);

        $decoded = json_decode($response, true);
        $content = data_get($decoded, 'choices.0.message.content');

        $orderData = json_decode($content, true) ?? [];
        
        // Add additional processing to handle ambiguities and alternatives
        $orderData = $this->enhanceOrderData($orderData, $transcription);
        
        return $orderData;
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
        $prompt = "Generate a personalized food recommendation based on these preferences: " . json_encode($orderData);
        
        $response = $this->openAi->chat([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful food recommendation assistant that provides short, useful suggestions.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.7,
            'max_tokens' => 150,
        ]);
        
        $decoded = json_decode($response, true);
        return data_get($decoded, 'choices.0.message.content', 'No recommendation available');
    }
} 