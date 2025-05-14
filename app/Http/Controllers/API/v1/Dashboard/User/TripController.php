<?php

namespace App\Http\Controllers\API\v1\Dashboard\User;

use App\Helpers\ResponseError;
use App\Http\Resources\TripResource;
use App\Models\Order;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends UserBaseController
{
    /**
     * Get trip by ID
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $trip = Trip::with(['driver', 'vehicle', 'locations'])
                ->where(['id' => $id])
                ->first();

            if (!$trip) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ]);
            }

            // Check if the trip belongs to an order for this user
            $userOrderIds = Order::where('user_id', auth('sanctum')->id())->pluck('id')->toArray();
            $tripOrderIds = $trip->orders()->pluck('orders.id')->toArray();
            
            // Check if any of the trip's orders belong to the user
            if (!array_intersect($userOrderIds, $tripOrderIds)) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_403,
                    'message' => __('errors.' . ResponseError::ERROR_403, locale: $this->language)
                ]);
            }

            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                TripResource::make($trip)
            );
        } catch (\Exception $e) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_400,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get trip for an order
     *
     * @param int $orderId
     * @return JsonResponse
     */
    public function getOrderTrip(int $orderId): JsonResponse
    {
        try {
            // Check if order belongs to the user
            $order = Order::where('id', $orderId)
                ->where('user_id', auth('sanctum')->id())
                ->first();

            if (!$order) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ]);
            }

            // Find the trip for this order using the many-to-many relationship
            $orderTrip = $order->trips()->first();
            
            if (!$orderTrip) {
                // If no trip is found in the many-to-many relationship, try the old hasOne relationship
                $trip = $order->trip;
                
                if (!$trip) {
                    return $this->onErrorResponse([
                        'code'    => ResponseError::ERROR_404,
                        'message' => __('errors.' . ResponseError::ORDER_TRIP_NOT_FOUND, locale: $this->language)
                    ]);
                }
            } else {
                $trip = $orderTrip;
            }
            
            // Load the trip's relationships
            $trip->load(['driver', 'vehicle', 'locations']);
            
            // If there are no locations, create a default one
            if ($trip->locations->isEmpty() && $order->shop) {
                $shop = $order->shop;
                $trip->locations()->create([
                    'address' => $shop->address ?? 'Delivery destination', 
                    'lat' => $shop->location['lat'] ?? ($shop->location['latitude'] ?? 0),
                    'lng' => $shop->location['lng'] ?? ($shop->location['longitude'] ?? 0),
                    'sequence' => 0,
                    'eta_minutes' => 30,
                    'status' => 'pending'
                ]);
                
                // Reload locations
                $trip->load('locations');
            }

            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                TripResource::make($trip)
            );
        } catch (\Exception $e) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_400,
                'message' => $e->getMessage()
            ]);
        }
    }
} 