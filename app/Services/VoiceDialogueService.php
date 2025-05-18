<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Product;
use App\Models\PaymentMethod;
use App\Models\Currency;
use App\Models\User;
use App\Services\VoiceOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VoiceDialogueService
{
    /**
     * @var VoiceOrderService
     */
    protected $voiceService;
    
    /**
     * @var array Current dialogue state
     */
    protected $dialogueState = [];
    
    /**
     * @var array Intent detection patterns
     */
    protected $intentPatterns = [
        'add_to_cart' => [
            'i want', 'add', 'buy', 'purchase', 'get me', 'order'
        ],
        'remove_from_cart' => [
            'remove', 'delete', 'take out', 'don\'t want'
        ],
        'checkout' => [
            'checkout', 'pay', 'complete order', 'finish order', 'place order'
        ],
        'payment_method' => [
            'pay with', 'use', 'payment', 'method', 'card', 'cash'
        ],
        'currency' => [
            'currency', 'price in', 'convert to', 'change currency'
        ],
        'quantity' => [
            'quantity', 'pieces', 'servings', 'how many'
        ]
    ];
    
    /**
     * Constructor
     * 
     * @param VoiceOrderService $voiceService
     */
    public function __construct(VoiceOrderService $voiceService = null)
    {
        $this->voiceService = $voiceService ?? new VoiceOrderService();
        $this->initializeDialogueState();
    }
    
    /**
     * Initialize dialogue state
     */
    protected function initializeDialogueState()
    {
        $this->dialogueState = [
            'step' => 'initial',
            'products' => [],
            'cart' => [],
            'selected_payment' => null,
            'currency' => $this->getActiveCurrency(),
            'last_transcription' => '',
            'context' => [],
            'session_id' => Str::uuid()->toString()
        ];
    }
    
    /**
     * Process voice command and handle dialogue flow
     * 
     * @param string $audioFilePath
     * @param string $languageCode
     * @return array Response with actions and dialogue state
     */
    public function processVoiceCommand(string $audioFilePath, string $languageCode = 'en-US'): array
    {
        // Enhance speech recognition with domain-specific phrases
        $this->voiceService->setCustomPhrases($this->getCustomPhrases());
        
        // Transcribe the audio
        $transcription = $this->voiceService->transcribeAudio($audioFilePath, $languageCode);
        
        if (!$transcription['success']) {
            return [
                'success' => false,
                'message' => 'Failed to transcribe audio: ' . ($transcription['error'] ?? 'Unknown error'),
                'dialogue_state' => $this->dialogueState
            ];
        }
        
        // Store transcription in dialogue state
        $this->dialogueState['last_transcription'] = $transcription['transcription'];
        
        // Process the intent
        $intent = $this->detectIntent($transcription['transcription']);
        
        // Handle the intent
        $response = $this->handleIntent($intent, $transcription['transcription']);
        
        // Add transcription details to response
        $response['transcription'] = [
            'text' => $transcription['transcription'],
            'confidence' => $transcription['confidence'],
            'language' => $transcription['language']
        ];
        
        return $response;
    }
    
    /**
     * Detect the intent from transcription
     * 
     * @param string $transcription
     * @return array Intent data
     */
    protected function detectIntent(string $transcription): array
    {
        $transcription = strtolower($transcription);
        $primaryIntent = 'unknown';
        $confidence = 0;
        $entities = [];
        
        // Check for each intent pattern
        foreach ($this->intentPatterns as $intent => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($transcription, strtolower($pattern)) !== false) {
                    $primaryIntent = $intent;
                    $confidence = 0.8; // Basic confidence score
                    break 2;
                }
            }
        }
        
        // Extract entities based on intent
        switch ($primaryIntent) {
            case 'add_to_cart':
                $entities = $this->extractProductEntities($transcription);
                break;
                
            case 'payment_method':
                $entities = $this->extractPaymentMethodEntities($transcription);
                break;
                
            case 'currency':
                $entities = $this->extractCurrencyEntities($transcription);
                break;
                
            case 'quantity':
                $entities = $this->extractQuantityEntities($transcription);
                break;
        }
        
        return [
            'intent' => $primaryIntent,
            'confidence' => $confidence,
            'entities' => $entities
        ];
    }
    
    /**
     * Handle the detected intent
     * 
     * @param array $intent Intent data
     * @param string $transcription Original transcription
     * @return array Response with actions
     */
    protected function handleIntent(array $intent, string $transcription): array
    {
        $response = [
            'success' => true,
            'intent' => $intent['intent'],
            'actions' => [],
            'message' => '',
            'dialogue_state' => $this->dialogueState
        ];
        
        switch ($intent['intent']) {
            case 'add_to_cart':
                $response = $this->handleAddToCart($intent, $response);
                break;
                
            case 'remove_from_cart':
                $response = $this->handleRemoveFromCart($intent, $response);
                break;
                
            case 'checkout':
                $response = $this->handleCheckout($intent, $response);
                break;
                
            case 'payment_method':
                $response = $this->handlePaymentMethod($intent, $response);
                break;
                
            case 'currency':
                $response = $this->handleCurrencyChange($intent, $response);
                break;
                
            case 'quantity':
                $response = $this->handleQuantityChange($intent, $response);
                break;
                
            default:
                // If no specific intent was detected, try to find products
                $productEntities = $this->extractProductEntities($transcription);
                if (!empty($productEntities)) {
                    $intent['intent'] = 'add_to_cart';
                    $intent['entities'] = $productEntities;
                    $response = $this->handleAddToCart($intent, $response);
                } else {
                    $response['message'] = "I'm not sure what you'd like to do. You can order items, check out, or select a payment method.";
                }
                break;
        }
        
        return $response;
    }
    
    /**
     * Handle adding products to cart
     * 
     * @param array $intent Intent data
     * @param array $response Current response
     * @return array Updated response
     */
    protected function handleAddToCart(array $intent, array $response): array
    {
        $products = [];
        $failedProducts = [];
        
        foreach ($intent['entities'] as $entity) {
            if ($entity['type'] === 'product') {
                $product = $this->findProduct($entity['value']);
                
                if ($product) {
                    $quantity = $entity['quantity'] ?? 1;
                    $this->addToCart($product, $quantity);
                    
                    $products[] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => $this->formatPrice($product->price, $this->dialogueState['currency']),
                        'quantity' => $quantity
                    ];
                } else {
                    $failedProducts[] = $entity['value'];
                }
            }
        }
        
        if (!empty($products)) {
            $response['actions'][] = [
                'type' => 'add_to_cart',
                'products' => $products
            ];
            
            $response['message'] = count($products) === 1 
                ? "Added {$products[0]['name']} to your cart." 
                : "Added " . count($products) . " items to your cart.";
            
            // If there were some failed products, mention them
            if (!empty($failedProducts)) {
                $response['message'] .= " I couldn't find: " . implode(', ', $failedProducts);
            }
            
            // Update dialogue state
            $this->dialogueState['step'] = 'items_added';
            $this->dialogueState['products'] = array_merge($this->dialogueState['products'], $products);
            
            // If this is the first product, suggest payment options
            if (count($this->dialogueState['products']) === count($products)) {
                $paymentMethods = $this->getAvailablePaymentMethods();
                if (!empty($paymentMethods)) {
                    $response['message'] .= " Would you like to checkout? You can pay with " . 
                        implode(', ', array_column($paymentMethods, 'name')) . ".";
                    
                    $response['actions'][] = [
                        'type' => 'suggest_payment_methods',
                        'payment_methods' => $paymentMethods
                    ];
                }
            }
        } else {
            $response['message'] = "I couldn't find the products you mentioned. Please try again.";
            if (!empty($failedProducts)) {
                $response['message'] .= " I couldn't find: " . implode(', ', $failedProducts);
            }
        }
        
        return $response;
    }
    
    /**
     * Handle removing products from cart
     * 
     * @param array $intent Intent data
     * @param array $response Current response
     * @return array Updated response
     */
    protected function handleRemoveFromCart(array $intent, array $response): array
    {
        $removedProducts = [];
        
        foreach ($intent['entities'] as $entity) {
            if ($entity['type'] === 'product') {
                $product = $this->findProductInCart($entity['value']);
                
                if ($product) {
                    $this->removeFromCart($product['id']);
                    $removedProducts[] = $product['name'];
                }
            }
        }
        
        if (!empty($removedProducts)) {
            $response['actions'][] = [
                'type' => 'remove_from_cart',
                'products' => $removedProducts
            ];
            
            $response['message'] = count($removedProducts) === 1 
                ? "Removed {$removedProducts[0]} from your cart." 
                : "Removed " . implode(', ', $removedProducts) . " from your cart.";
            
            // Update dialogue state
            $this->dialogueState['cart'] = $this->getCartContents();
        } else {
            $response['message'] = "I couldn't find those items in your cart to remove.";
        }
        
        return $response;
    }
    
    /**
     * Handle checkout intent
     * 
     * @param array $intent Intent data
     * @param array $response Current response
     * @return array Updated response
     */
    protected function handleCheckout(array $intent, array $response): array
    {
        $cart = $this->getCartContents();
        
        if (empty($cart)) {
            $response['message'] = "Your cart is empty. Please add some items before checkout.";
            return $response;
        }
        
        $paymentMethods = $this->getAvailablePaymentMethods();
        
        if (empty($paymentMethods)) {
            $response['message'] = "No payment methods are available. Please contact support.";
            return $response;
        }
        
        if (!empty($this->dialogueState['selected_payment'])) {
            // User has already selected a payment method, proceed to checkout
            $response['actions'][] = [
                'type' => 'checkout',
                'payment_method' => $this->dialogueState['selected_payment'],
                'cart' => $cart,
                'total' => $this->getCartTotal()
            ];
            
            $response['message'] = "Processing your order with {$this->dialogueState['selected_payment']['name']}. Your total is " . 
                $this->formatPrice($this->getCartTotal(), $this->dialogueState['currency']) . ".";
            
            // Update dialogue state
            $this->dialogueState['step'] = 'checkout_completed';
        } else {
            // User needs to select a payment method first
            $response['actions'][] = [
                'type' => 'request_payment_method',
                'payment_methods' => $paymentMethods
            ];
            
            $response['message'] = "Please select a payment method: " . implode(', ', array_column($paymentMethods, 'name')) . ".";
            
            // Update dialogue state
            $this->dialogueState['step'] = 'awaiting_payment_selection';
        }
        
        return $response;
    }
    
    /**
     * Handle payment method selection
     * 
     * @param array $intent Intent data
     * @param array $response Current response
     * @return array Updated response
     */
    protected function handlePaymentMethod(array $intent, array $response): array
    {
        $paymentMethods = $this->getAvailablePaymentMethods();
        $selectedMethod = null;
        
        foreach ($intent['entities'] as $entity) {
            if ($entity['type'] === 'payment_method') {
                foreach ($paymentMethods as $method) {
                    if (stripos($method['name'], $entity['value']) !== false) {
                        $selectedMethod = $method;
                        break 2;
                    }
                }
            }
        }
        
        if ($selectedMethod) {
            $this->dialogueState['selected_payment'] = $selectedMethod;
            $this->dialogueState['step'] = 'payment_selected';
            
            $response['actions'][] = [
                'type' => 'select_payment_method',
                'payment_method' => $selectedMethod
            ];
            
            $response['message'] = "Payment method set to {$selectedMethod['name']}. ";
            
            // If cart has items, suggest checkout
            $cart = $this->getCartContents();
            if (!empty($cart)) {
                $response['message'] .= "Your total is " . $this->formatPrice($this->getCartTotal(), $this->dialogueState['currency']) . 
                    ". Say 'checkout' to complete your order.";
                
                $response['actions'][] = [
                    'type' => 'suggest_checkout',
                    'cart' => $cart,
                    'total' => $this->getCartTotal()
                ];
            }
        } else {
            $response['message'] = "I couldn't recognize that payment method. Available options are: " . 
                implode(', ', array_column($paymentMethods, 'name')) . ".";
                
            $response['actions'][] = [
                'type' => 'list_payment_methods',
                'payment_methods' => $paymentMethods
            ];
        }
        
        return $response;
    }
    
    /**
     * Handle currency change
     * 
     * @param array $intent Intent data
     * @param array $response Current response
     * @return array Updated response
     */
    protected function handleCurrencyChange(array $intent, array $response): array
    {
        $availableCurrencies = $this->getAvailableCurrencies();
        $selectedCurrency = null;
        
        foreach ($intent['entities'] as $entity) {
            if ($entity['type'] === 'currency') {
                foreach ($availableCurrencies as $currency) {
                    if (strtolower($currency['code']) === strtolower($entity['value']) || 
                        strtolower($currency['name']) === strtolower($entity['value'])) {
                        $selectedCurrency = $currency;
                        break 2;
                    }
                }
            }
        }
        
        if ($selectedCurrency) {
            $this->dialogueState['currency'] = $selectedCurrency;
            
            $response['actions'][] = [
                'type' => 'change_currency',
                'currency' => $selectedCurrency
            ];
            
            $response['message'] = "Currency changed to {$selectedCurrency['name']} ({$selectedCurrency['code']}).";
            
            // If cart has items, display updated prices
            $cart = $this->getCartContents();
            if (!empty($cart)) {
                $cartWithPrices = $this->getCartWithFormattedPrices($selectedCurrency);
                $response['actions'][] = [
                    'type' => 'update_cart_prices',
                    'cart' => $cartWithPrices,
                    'total' => $this->formatPrice($this->getCartTotal(), $selectedCurrency)
                ];
            }
        } else {
            $response['message'] = "I couldn't recognize that currency. Available options are: " . 
                implode(', ', array_map(function($curr) {
                    return "{$curr['name']} ({$curr['code']})";
                }, $availableCurrencies)) . ".";
                
            $response['actions'][] = [
                'type' => 'list_currencies',
                'currencies' => $availableCurrencies
            ];
        }
        
        return $response;
    }
    
    /**
     * Handle quantity change
     * 
     * @param array $intent Intent data
     * @param array $response Current response
     * @return array Updated response
     */
    protected function handleQuantityChange(array $intent, array $response): array
    {
        $productName = null;
        $quantity = null;
        
        foreach ($intent['entities'] as $entity) {
            if ($entity['type'] === 'product') {
                $productName = $entity['value'];
            } else if ($entity['type'] === 'quantity') {
                $quantity = $entity['value'];
            }
        }
        
        if ($productName && $quantity) {
            $product = $this->findProductInCart($productName);
            
            if ($product) {
                $this->updateCartItemQuantity($product['id'], $quantity);
                
                $response['actions'][] = [
                    'type' => 'update_quantity',
                    'product' => $product['name'],
                    'quantity' => $quantity
                ];
                
                $response['message'] = "Updated quantity of {$product['name']} to {$quantity}.";
                
                // Update dialogue state
                $this->dialogueState['cart'] = $this->getCartContents();
            } else {
                $response['message'] = "I couldn't find {$productName} in your cart.";
            }
        } else {
            $response['message'] = "Please specify both the product and the quantity you want to change.";
        }
        
        return $response;
    }
    
    /**
     * Extract product entities from transcription
     * 
     * @param string $transcription
     * @return array Product entities
     */
    protected function extractProductEntities(string $transcription): array
    {
        $entities = [];
        $products = $this->searchProducts($transcription);
        
        foreach ($products as $product) {
            $entities[] = [
                'type' => 'product',
                'value' => $product->name,
                'id' => $product->id,
                'confidence' => 0.8
            ];
        }
        
        return $entities;
    }
    
    /**
     * Extract payment method entities from transcription
     * 
     * @param string $transcription
     * @return array Payment method entities
     */
    protected function extractPaymentMethodEntities(string $transcription): array
    {
        $entities = [];
        $lowercaseTranscription = strtolower($transcription);
        $paymentMethods = $this->getAvailablePaymentMethods();
        
        foreach ($paymentMethods as $method) {
            if (strpos($lowercaseTranscription, strtolower($method['name'])) !== false) {
                $entities[] = [
                    'type' => 'payment_method',
                    'value' => $method['name'],
                    'id' => $method['id'],
                    'confidence' => 0.9
                ];
            }
        }
        
        // Common payment terms
        $commonPaymentTerms = [
            'credit card' => 'Credit Card',
            'debit card' => 'Debit Card',
            'cash' => 'Cash',
            'paypal' => 'PayPal',
            'mobile money' => 'Mobile Money',
            'mpesa' => 'M-Pesa'
        ];
        
        foreach ($commonPaymentTerms as $term => $formalName) {
            if (strpos($lowercaseTranscription, $term) !== false) {
                // Only add if we didn't already find it
                $found = false;
                foreach ($entities as $entity) {
                    if (strtolower($entity['value']) === strtolower($formalName)) {
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    $entities[] = [
                        'type' => 'payment_method',
                        'value' => $formalName,
                        'confidence' => 0.7
                    ];
                }
            }
        }
        
        return $entities;
    }
    
    /**
     * Extract currency entities from transcription
     * 
     * @param string $transcription
     * @return array Currency entities
     */
    protected function extractCurrencyEntities(string $transcription): array
    {
        $entities = [];
        $lowercaseTranscription = strtolower($transcription);
        $currencies = $this->getAvailableCurrencies();
        
        foreach ($currencies as $currency) {
            if (strpos($lowercaseTranscription, strtolower($currency['code'])) !== false || 
                strpos($lowercaseTranscription, strtolower($currency['name'])) !== false) {
                $entities[] = [
                    'type' => 'currency',
                    'value' => $currency['code'],
                    'name' => $currency['name'],
                    'confidence' => 0.9
                ];
            }
        }
        
        return $entities;
    }
    
    /**
     * Extract quantity entities from transcription
     * 
     * @param string $transcription
     * @return array Quantity entities
     */
    protected function extractQuantityEntities(string $transcription): array
    {
        $entities = [];
        
        // Basic pattern to find numbers
        preg_match_all('/\b(\d+)\b/', $transcription, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $quantity) {
                $entities[] = [
                    'type' => 'quantity',
                    'value' => (int)$quantity,
                    'confidence' => 0.9
                ];
            }
        }
        
        return $entities;
    }
    
    /**
     * Get custom phrases to improve speech recognition
     * 
     * @return array Custom phrases
     */
    protected function getCustomPhrases(): array
    {
        $phrases = [];
        
        // Add product names
        $products = $this->getPopularProducts(20);
        foreach ($products as $product) {
            $phrases[] = $product->name;
        }
        
        // Add payment method names
        $paymentMethods = $this->getAvailablePaymentMethods();
        foreach ($paymentMethods as $method) {
            $phrases[] = $method['name'];
            $phrases[] = "pay with {$method['name']}";
        }
        
        // Add currency names and codes
        $currencies = $this->getAvailableCurrencies();
        foreach ($currencies as $currency) {
            $phrases[] = $currency['name'];
            $phrases[] = $currency['code'];
            $phrases[] = "change to {$currency['name']}";
            $phrases[] = "prices in {$currency['name']}";
        }
        
        // Add common dialogue phrases
        $phrases = array_merge($phrases, [
            "add to cart", "remove from cart", "checkout",
            "complete order", "finish order", "place order",
            "change quantity", "how much", "total price"
        ]);
        
        return $phrases;
    }
    
    /**
     * Find a product by name
     * 
     * @param string $productName
     * @return Product|null
     */
    protected function findProduct(string $productName)
    {
        // In a real application, this would search the database
        // This is a placeholder implementation
        $products = $this->getPopularProducts(20);
        
        foreach ($products as $product) {
            if (stripos($product->name, $productName) !== false) {
                return $product;
            }
        }
        
        return null;
    }
    
    /**
     * Find a product in the cart by name
     * 
     * @param string $productName
     * @return array|null Product data from cart
     */
    protected function findProductInCart(string $productName)
    {
        foreach ($this->dialogueState['cart'] as $item) {
            if (stripos($item['name'], $productName) !== false) {
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * Search for products matching transcription
     * 
     * @param string $transcription
     * @return array Products
     */
    protected function searchProducts(string $transcription): array
    {
        // In a real application, this would use a more sophisticated search
        // This is a placeholder implementation
        $products = $this->getPopularProducts(20);
        $results = [];
        
        foreach ($products as $product) {
            if (stripos($transcription, strtolower($product->name)) !== false) {
                $results[] = $product;
            }
        }
        
        return $results;
    }
    
    /**
     * Get popular products for suggestions
     * 
     * @param int $limit
     * @return array Products
     */
    protected function getPopularProducts(int $limit = 10): array
    {
        // In a real application, this would get from database
        // This is a placeholder with sample data
        return Cache::remember('popular_products', 3600, function() use ($limit) {
            // Simulated products
            $products = [];
            $sampleProducts = [
                ['id' => 1, 'name' => 'Chicken Burger', 'price' => 8.99],
                ['id' => 2, 'name' => 'Vegetarian Pizza', 'price' => 12.50],
                ['id' => 3, 'name' => 'Greek Salad', 'price' => 7.25],
                ['id' => 4, 'name' => 'Grilled Chicken Wrap', 'price' => 6.99],
                ['id' => 5, 'name' => 'Fish and Chips', 'price' => 10.50],
                ['id' => 6, 'name' => 'Beef Burrito', 'price' => 9.75],
                ['id' => 7, 'name' => 'Sushi Platter', 'price' => 15.99],
                ['id' => 8, 'name' => 'Chicken Curry', 'price' => 11.25],
                ['id' => 9, 'name' => 'Pasta Carbonara', 'price' => 9.50],
                ['id' => 10, 'name' => 'Caesar Salad', 'price' => 6.75]
            ];
            
            foreach ($sampleProducts as $product) {
                $obj = new \stdClass();
                $obj->id = $product['id'];
                $obj->name = $product['name'];
                $obj->price = $product['price'];
                $products[] = $obj;
            }
            
            return $products;
        });
    }
    
    /**
     * Get available payment methods
     * 
     * @return array Payment methods
     */
    protected function getAvailablePaymentMethods(): array
    {
        // In a real application, this would get from database
        // This is a placeholder with sample data
        return [
            ['id' => 1, 'name' => 'Credit Card', 'code' => 'card'],
            ['id' => 2, 'name' => 'PayPal', 'code' => 'paypal'],
            ['id' => 3, 'name' => 'Cash on Delivery', 'code' => 'cod'],
            ['id' => 4, 'name' => 'M-Pesa', 'code' => 'mpesa']
        ];
    }
    
    /**
     * Get active currency
     * 
     * @return array Currency data
     */
    protected function getActiveCurrency(): array
    {
        // In a real application, this would get from session or user preferences
        // This is a placeholder with sample data
        return [
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'rate' => 1.0
        ];
    }
    
    /**
     * Get available currencies
     * 
     * @return array Currencies
     */
    protected function getAvailableCurrencies(): array
    {
        // In a real application, this would get from database
        // This is a placeholder with sample data
        return [
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'rate' => 1.0],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'rate' => 0.92],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'rate' => 0.79],
            ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'rate' => 2520.0]
        ];
    }
    
    /**
     * Add a product to cart
     * 
     * @param object $product
     * @param int $quantity
     * @return void
     */
    protected function addToCart($product, int $quantity = 1): void
    {
        // In a real application, this would interact with the session cart
        // This is a placeholder implementation
        $found = false;
        
        foreach ($this->dialogueState['cart'] as &$item) {
            if ($item['id'] === $product->id) {
                $item['quantity'] += $quantity;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $this->dialogueState['cart'][] = [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => $quantity
            ];
        }
    }
    
    /**
     * Remove a product from cart
     * 
     * @param int $productId
     * @return void
     */
    protected function removeFromCart(int $productId): void
    {
        // In a real application, this would interact with the session cart
        // This is a placeholder implementation
        foreach ($this->dialogueState['cart'] as $index => $item) {
            if ($item['id'] === $productId) {
                unset($this->dialogueState['cart'][$index]);
                $this->dialogueState['cart'] = array_values($this->dialogueState['cart']);
                break;
            }
        }
    }
    
    /**
     * Update cart item quantity
     * 
     * @param int $productId
     * @param int $quantity
     * @return void
     */
    protected function updateCartItemQuantity(int $productId, int $quantity): void
    {
        // In a real application, this would interact with the session cart
        // This is a placeholder implementation
        if ($quantity <= 0) {
            $this->removeFromCart($productId);
            return;
        }
        
        foreach ($this->dialogueState['cart'] as &$item) {
            if ($item['id'] === $productId) {
                $item['quantity'] = $quantity;
                break;
            }
        }
    }
    
    /**
     * Get cart contents
     * 
     * @return array Cart items
     */
    protected function getCartContents(): array
    {
        // In a real application, this would get from session
        // This just returns the dialogue state cart
        return $this->dialogueState['cart'];
    }
    
    /**
     * Get cart total
     * 
     * @return float Total price
     */
    protected function getCartTotal(): float
    {
        $total = 0;
        
        foreach ($this->dialogueState['cart'] as $item) {
            $total += $item['price'] * $item['quantity'];
        }
        
        return $total;
    }
    
    /**
     * Format price with currency
     * 
     * @param float $price
     * @param array $currency
     * @return string Formatted price
     */
    protected function formatPrice(float $price, array $currency): string
    {
        $convertedPrice = $price * $currency['rate'];
        return $currency['symbol'] . number_format($convertedPrice, 2);
    }
    
    /**
     * Get cart with formatted prices
     * 
     * @param array $currency
     * @return array Cart with formatted prices
     */
    protected function getCartWithFormattedPrices(array $currency): array
    {
        $cartWithPrices = [];
        
        foreach ($this->dialogueState['cart'] as $item) {
            $cartWithPrices[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'price' => $this->formatPrice($item['price'], $currency),
                'quantity' => $item['quantity'],
                'subtotal' => $this->formatPrice($item['price'] * $item['quantity'], $currency)
            ];
        }
        
        return $cartWithPrices;
    }
    
    /**
     * Get dialogue state
     * 
     * @return array Current dialogue state
     */
    public function getDialogueState(): array
    {
        return $this->dialogueState;
    }
    
    /**
     * Set dialogue state
     * 
     * @param array $state New state
     * @return self For method chaining
     */
    public function setDialogueState(array $state): self
    {
        $this->dialogueState = $state;
        return $this;
    }
} 