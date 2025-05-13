<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FoodIntelligenceService
{
    /**
     * Filter products based on AI-extracted intent, filters, and exclusions
     */
    public function filterProducts(array $orderData): Collection
    {
        $intent = $orderData['intent'] ?? null;
        $filters = $orderData['filters'] ?? [];
        $exclusions = $orderData['exclusions'] ?? [];
        
        $query = Product::query()
            ->with(['translation', 'category.translation', 'stocks'])
            ->where('active', 1);
            
        // Apply calorie filter if present
        if (in_array('low calorie', $filters) || in_array('low-calorie', $filters)) {
            $query->where('calories', '<', 500); // Example threshold
        }
        
        // Apply dietary preference filters
        if (in_array('vegetarian', $filters)) {
            $query->whereJsonContains('ingredient_tags', 'vegetarian');
        }
        
        if (in_array('vegan', $filters)) {
            $query->whereJsonContains('ingredient_tags', 'vegan');
        }
        
        // Apply allergen exclusions
        foreach ($exclusions as $exclusion) {
            if ($this->isAllergen($exclusion)) {
                $query->whereJsonDoesntContain('allergen_flags', $exclusion);
            }
        }
        
        // Order by popularity for best recommendations
        return $query->orderBy('popularity_score', 'desc')
            ->limit(10)
            ->get();
    }
    
    /**
     * Check if an exclusion term is likely an allergen
     */
    private function isAllergen(string $term): bool
    {
        $commonAllergens = [
            'peanuts', 'peanut', 'nuts', 'gluten', 'dairy', 'milk', 
            'eggs', 'egg', 'soy', 'fish', 'shellfish', 'wheat'
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
     * Generate a description for a product using AI
     */
    public function generateAIDescription(Product $product, $aiService): string
    {
        // This would use the AIOrderService to generate a description
        // Just a placeholder implementation
        return "AI-generated description would go here";
    }
} 