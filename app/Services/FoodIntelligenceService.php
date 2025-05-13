<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class FoodIntelligenceService
{
    /**
     * Filter products based on AI-extracted intent, filters, and exclusions
     */
    public function filterProducts(array $orderData, ?User $user = null): Collection
    {
        $intent = $orderData['intent'] ?? null;
        $filters = $orderData['filters'] ?? [];
        $exclusions = $orderData['exclusions'] ?? [];
        $cuisineType = $orderData['cuisine_type'] ?? '';
        $spiceLevel = $orderData['spice_level'] ?? '';
        
        $query = Product::query()
            ->with(['translation', 'category.translation', 'stocks'])
            ->where('active', 1);
            
        // Apply primary intent search - looking for the specific food mentioned
        if (!empty($intent)) {
            $query->whereHas('translation', function($q) use ($intent) {
                $q->where('title', 'like', '%' . $intent . '%')
                  ->orWhere('description', 'like', '%' . $intent . '%');
            })
            ->orWhereHas('category.translation', function($q) use ($intent) {
                $q->where('title', 'like', '%' . $intent . '%');
            });
        }
        
        // Apply cuisine type filter if specified
        if (!empty($cuisineType)) {
            $query->whereHas('category.translation', function($q) use ($cuisineType) {
                $q->where('title', 'like', '%' . $cuisineType . '%');
            })
            ->orWhereJsonContains('ingredient_tags', strtolower($cuisineType));
        }
        
        // Apply calorie filters
        if (in_array('low calorie', $filters) || in_array('low-calorie', $filters)) {
            $query->where('calories', '<', 500);
        } elseif (in_array('high protein', $filters) || in_array('high-protein', $filters)) {
            $query->where('protein_grams', '>', 15);
        }
        
        // Apply dietary preference filters
        $this->applyDietaryFilters($query, $filters);
        
        // Apply allergen exclusions
        foreach ($exclusions as $exclusion) {
            if ($this->isAllergen($exclusion)) {
                $query->whereJsonDoesntContain('allergen_flags', $exclusion);
            } else {
                // Exclude products with this ingredient
                $query->where(function($q) use ($exclusion) {
                    $q->whereJsonDoesntContain('ingredient_tags', $exclusion)
                      ->orWhereNull('ingredient_tags');
                });
            }
        }
        
        // Apply personalization if user is available
        if ($user) {
            $personalizedProducts = $this->getPersonalizedProductIds($user);
            
            if (!empty($personalizedProducts)) {
                // Boost personalized products in results
                $query->orderByRaw("CASE WHEN id IN (" . implode(',', $personalizedProducts) . ") THEN 0 ELSE 1 END");
            }
        }
        
        // Order results by multiple factors
        $results = $query->orderBy('popularity_score', 'desc')
            ->orderBy('average_rating', 'desc')
            ->limit(15)
            ->get();
            
        // If we don't have enough results, fallback to broader search
        if ($results->count() < 3 && !empty($intent)) {
            return $this->fallbackSearch($intent, $filters, $exclusions);
        }
        
        return $results;
    }
    
    /**
     * Apply dietary filters to the query
     */
    private function applyDietaryFilters($query, array $filters): void
    {
        $dietaryFilters = [
            'vegetarian' => 'vegetarian',
            'vegan' => 'vegan',
            'halal' => 'halal',
            'kosher' => 'kosher',
            'gluten-free' => 'gluten_free',
            'dairy-free' => 'dairy_free',
            'keto' => 'keto_friendly',
            'paleo' => 'paleo_friendly',
        ];
        
        foreach ($filters as $filter) {
            $filter = strtolower($filter);
            
            if (isset($dietaryFilters[$filter])) {
                $tagName = $dietaryFilters[$filter];
                $query->whereJsonContains('ingredient_tags', $tagName);
            }
        }
    }
    
    /**
     * Fallback search when specific filters return too few results
     */
    private function fallbackSearch(string $intent, array $filters, array $exclusions): Collection
    {
        // Simplified query with just the most essential filters
        $query = Product::query()
            ->with(['translation', 'category.translation', 'stocks'])
            ->where('active', 1)
            ->whereHas('translation', function($q) use ($intent) {
                $q->where('title', 'like', '%' . $intent . '%')
                  ->orWhere('description', 'like', '%' . $intent . '%');
            });
        
        // Only keep allergen exclusions for safety
        foreach ($exclusions as $exclusion) {
            if ($this->isAllergen($exclusion)) {
                $query->whereJsonDoesntContain('allergen_flags', $exclusion);
            }
        }
        
        return $query->orderBy('popularity_score', 'desc')
            ->limit(10)
            ->get();
    }
    
    /**
     * Get personalized product IDs based on user's order history
     */
    private function getPersonalizedProductIds(User $user): array
    {
        return Cache::remember('user_personalized_products_' . $user->id, 1440, function() use ($user) {
            // Get products the user has ordered before
            $orderHistory = OrderDetail::join('orders', 'order_details.order_id', '=', 'orders.id')
                ->where('orders.user_id', $user->id)
                ->where('orders.created_at', '>=', now()->subMonths(6))
                ->select('order_details.product_id', DB::raw('COUNT(*) as order_count'))
                ->groupBy('order_details.product_id')
                ->orderBy('order_count', 'desc')
                ->limit(20)
                ->pluck('product_id')
                ->toArray();
                
            return $orderHistory;
        });
    }
    
    /**
     * Check if an exclusion term is likely an allergen
     */
    private function isAllergen(string $term): bool
    {
        $commonAllergens = [
            'peanuts', 'peanut', 'nuts', 'tree nuts', 'gluten', 'dairy', 'milk', 
            'eggs', 'egg', 'soy', 'fish', 'shellfish', 'wheat', 'sesame', 'sulphites',
            'mustard', 'celery', 'lupin', 'molluscs'
        ];
        
        return in_array(strtolower($term), $commonAllergens);
    }
    
    /**
     * Update product popularity score based on order frequency
     */
    public function updatePopularityScores(): void
    {
        // Get products ordered in the last 30 days with count
        $popularProducts = DB::table('order_details')
            ->join('orders', 'order_details.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', now()->subDays(30))
            ->select('order_details.product_id', DB::raw('COUNT(*) as order_count'))
            ->groupBy('order_details.product_id')
            ->get();
            
        foreach ($popularProducts as $product) {
            Product::where('id', $product->product_id)
                ->update(['popularity_score' => $product->order_count]);
        }
    }
    
    /**
     * Generate product recommendations based on current cart contents
     */
    public function generateCartRecommendations(array $cartProductIds, int $limit = 4): Collection
    {
        if (empty($cartProductIds)) {
            return collect();
        }
        
        // Find products frequently ordered together
        $relatedProducts = DB::table('order_details as od1')
            ->join('order_details as od2', function($join) use ($cartProductIds) {
                $join->on('od1.order_id', '=', 'od2.order_id')
                    ->whereIn('od1.product_id', $cartProductIds)
                    ->whereNotIn('od2.product_id', $cartProductIds);
            })
            ->select('od2.product_id', DB::raw('COUNT(*) as frequency'))
            ->groupBy('od2.product_id')
            ->orderBy('frequency', 'desc')
            ->limit($limit)
            ->pluck('product_id')
            ->toArray();
            
        return Product::whereIn('id', $relatedProducts)
            ->with(['translation', 'stocks'])
            ->where('active', 1)
            ->get();
    }
    
    /**
     * Generate a description for a product using AI
     */
    public function generateAIDescription(Product $product, AIOrderService $aiService): string
    {
        $metadata = [
            'name' => $product->translation->title ?? $product->title,
            'ingredients' => $product->ingredient_tags ?? [],
            'allergens' => $product->allergen_flags ?? [],
            'calories' => $product->calories,
            'category' => $product->category->translation->title ?? $product->category->title ?? '',
        ];
        
        $prompt = "Generate a detailed food description for: " . json_encode($metadata);
        
        return $aiService->generateRecommendation(['prompt' => $prompt]);
    }
} 