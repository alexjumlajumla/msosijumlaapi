<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Http\Controllers\API\v1\Dashboard\Admin\AdminBaseController;
use App\Models\Trip;
use App\Models\TripLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TripTrackingController extends AdminBaseController
{
    /**
     * Update current driver location
     */
    public function updateLocation(Request $request, Trip $trip): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'speed' => 'nullable|numeric',
            'bearing' => 'nullable|numeric',
            'accuracy' => 'nullable|numeric',
        ]);

        // Store in trip meta
        $meta = $trip->meta ?? [];
        $meta['current_location'] = [
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'speed' => $data['speed'] ?? null,
            'bearing' => $data['bearing'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'updated_at' => now()->toIso8601String(),
        ];
        
        // Store location history (last 50 points)
        $history = $meta['location_history'] ?? [];
        array_unshift($history, $meta['current_location']);
        $meta['location_history'] = array_slice($history, 0, 50);
        
        $trip->update(['meta' => $meta]);
        
        // Check if we're near any waypoints and update status if needed
        $this->checkWaypoints($trip, $data['lat'], $data['lng']);
        
        // Store for real-time access
        $this->storeRealtimeData($trip->id, $data);
        
        return $this->successResponse('Location updated', $meta['current_location']);
    }
    
    /**
     * Check if driver is near any waypoints
     */
    private function checkWaypoints(Trip $trip, float $lat, float $lng): void
    {
        $pendingLocations = $trip->locations()
            ->where('status', 'pending')
            ->orderBy('sequence')
            ->get();
            
        if ($pendingLocations->isEmpty()) {
            return;
        }
        
        $nextLocation = $pendingLocations->first();
        $distance = $this->calculateDistance($lat, $lng, $nextLocation->lat, $nextLocation->lng);
        
        // If within 50 meters of the next waypoint
        if ($distance <= 0.05) {
            $nextLocation->update([
                'status' => 'arrived',
                'arrived_at' => now(),
            ]);
            
            // If all locations are completed, mark trip as completed
            $pendingCount = $trip->locations()->where('status', 'pending')->count();
            if ($pendingCount === 0) {
                $trip->update(['status' => 'completed']);
            }
        }
    }
    
    /**
     * Calculate distance between two points in kilometers
     */
    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
    
    /**
     * Store location for real-time access
     */
    private function storeRealtimeData(int $tripId, array $data): void
    {
        Cache::put("trip_location:$tripId", [
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'speed' => $data['speed'] ?? null,
            'bearing' => $data['bearing'] ?? null,
            'timestamp' => now()->timestamp,
        ], now()->addMinutes(10));
    }
    
    /**
     * Get current driver location 
     */
    public function getLocation(Trip $trip): JsonResponse
    {
        // Try to get from cache first for real-time data
        $cacheKey = "trip_location:{$trip->id}";
        $cachedLocation = Cache::get($cacheKey);
        
        if ($cachedLocation) {
            return $this->successResponse('Current location', $cachedLocation);
        }
        
        // Fall back to stored location in trip meta
        $meta = $trip->meta ?? [];
        $location = $meta['current_location'] ?? null;
        
        if (!$location) {
            return $this->successResponse('No location data available', [
                'lat' => $trip->start_lat,
                'lng' => $trip->start_lng,
                'is_start_location' => true,
            ]);
        }
        
        return $this->successResponse('Current location', $location);
    }
    
    /**
     * Start a trip
     */
    public function startTrip(Trip $trip): JsonResponse
    {
        if ($trip->status !== 'planned') {
            return $this->errorResponse('Trip is already in progress or completed', 400);
        }
        
        $trip->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        
        return $this->successResponse('Trip started', $trip);
    }
    
    /**
     * Complete a trip
     */
    public function completeTrip(Trip $trip): JsonResponse
    {
        if ($trip->status !== 'in_progress') {
            return $this->errorResponse('Trip is not in progress', 400);
        }
        
        $trip->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        
        // Mark all remaining waypoints as visited
        $trip->locations()
            ->where('status', 'pending')
            ->update(['status' => 'arrived', 'arrived_at' => now()]);
        
        return $this->successResponse('Trip completed', $trip);
    }
    
    /**
     * Get all active trips
     */
    public function activeTrips(): JsonResponse
    {
        $trips = Trip::where('status', 'in_progress')
            ->with(['locations', 'driver', 'vehicle'])
            ->get();
            
        return $this->successResponse('Active trips', $trips);
    }
} 