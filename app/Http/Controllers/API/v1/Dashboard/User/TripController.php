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

            // Check if trip should be visible for this order status
            if (!$order->isTripVisible()) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ORDER_TRIP_NOT_FOUND, locale: $this->language)
                ]);
            }

            // Get the first trip for this order
            $trip = $order->trips()->with(['driver', 'vehicle', 'locations'])->first();
            
            if (!$trip) {
                // Try to create a new trip for this order
                if ($order->status === Order::STATUS_ON_A_WAY || $order->status === Order::STATUS_SHIPPED) {
                    // Format address as a string if it's an array
                    $addressString = 'Unknown';
                    if (isset($order->address)) {
                        if (is_array($order->address)) {
                            $addressString = implode(', ', array_filter([
                                $order->address['address'] ?? '',
                                $order->address['house'] ?? '',
                                $order->address['street'] ?? '',
                                $order->address['city'] ?? '',
                            ]));
                            
                            if (empty(trim($addressString))) {
                                $addressString = 'Address from Order #' . $order->id;
                            }
                        } else {
                            $addressString = (string)$order->address;
                        }
                    }

                    // Create a new Trip for the order
                    $trip = Trip::create([
                        'name' => 'Trip for Order #' . $order->id,
                        'start_address' => $addressString,
                        'start_lat' => is_array($order->location) ? 
                                    ($order->location['lat'] ?? $order->location['latitude'] ?? 0) : 0,
                        'start_lng' => is_array($order->location) ? 
                                    ($order->location['lng'] ?? $order->location['longitude'] ?? 0) : 0,
                        'scheduled_at' => now(),
                        'status' => $order->status === Order::STATUS_DELIVERED ? 'completed' : 'in_progress',
                    ]);

                    // Associate the order with the trip
                    $order->trips()->attach($trip->id, [
                        'sequence' => 1, 
                        'status' => $order->status === Order::STATUS_DELIVERED ? 'delivered' : 'pending'
                    ]);
                    
                    // Reload the trip with relationships
                    $trip->load(['driver', 'vehicle', 'locations']);
                } else {
                    return $this->onErrorResponse([
                        'code'    => ResponseError::ERROR_404,
                        'message' => __('errors.' . ResponseError::ORDER_TRIP_NOT_FOUND, locale: $this->language)
                    ]);
                }
            }

            // If trip exists but order is delivered, update trip status to completed
            if ($trip && $order->status === Order::STATUS_DELIVERED && $trip->status !== 'completed') {
                $trip->update(['status' => 'completed']);
                $order->trips()->updateExistingPivot($trip->id, ['status' => 'delivered']);
                $trip->refresh();
            }
            
            // If there are no locations, create a default one using shop location
            if ($trip->locations->isEmpty() && $order->shop) {
                $shop = $order->shop;
                $trip->locations()->create([
                    'address' => $shop->address ?? 'Delivery destination', 
                    'lat' => $shop->location['lat'] ?? ($shop->location['latitude'] ?? 0),
                    'lng' => $shop->location['lng'] ?? ($shop->location['longitude'] ?? 0),
                    'sequence' => 0,
                    'eta_minutes' => 30,
                    'status' => $order->status === Order::STATUS_DELIVERED ? 'arrived' : 'pending'
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