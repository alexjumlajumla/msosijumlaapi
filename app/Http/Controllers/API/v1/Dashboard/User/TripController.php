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
            $order = Order::where('id', $trip->order_id)
                ->where('user_id', auth('sanctum')->id())
                ->first();

            if (!$order) {
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

            // Find the trip for this order
            $trip = Trip::with(['driver', 'vehicle', 'locations'])
                ->where('order_id', $orderId)
                ->first();

            if (!$trip) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ORDER_TRIP_NOT_FOUND, locale: $this->language)
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
} 