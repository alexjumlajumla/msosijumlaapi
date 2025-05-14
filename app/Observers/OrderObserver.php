<?php

namespace App\Observers;

use App\Jobs\AttachDeliveryMan;
use App\Models\Language;
use App\Models\Order;
use App\Models\PushNotification;
use App\Models\Settings;
use App\Services\ModelLogService\ModelLogService;
use App\Traits\Notification;
use DB;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;
use App\Services\OrderNotificationService;
use Throwable;
use App\Models\Trip;

class OrderObserver
{
    use Notification;

    /**
     * Handle the Brand "created" event.
     *
     * @param Order $order
     * @return void
     */
    public function created(Order $order): void
    {
        if ($order->status === Order::STATUS_READY && empty($order->deliveryman) && $this->autoDeliveryMan()) {
            AttachDeliveryMan::dispatchAfterResponse($order, $this->language());
        }

        try {
            // Format address as a string if it's an array
            $addressString = 'Unknown';
            if (isset($order->address)) {
                if (is_array($order->address)) {
                    // Try to extract meaningful address information
                    $addressString = implode(', ', array_filter([
                        $order->address['address'] ?? '',
                        $order->address['house'] ?? '',
                        $order->address['street'] ?? '',
                        $order->address['city'] ?? '',
                    ]));
                    
                    // If still empty after trying to combine array elements
                    if (empty(trim($addressString))) {
                        $addressString = 'Address from Order #' . $order->id;
                    }
                } else {
                    // If it's already a string
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
                'status' => 'planned',
            ]);

            // Associate the order with the trip
            $order->trip()->attach($trip->id, ['sequence' => 1, 'status' => 'pending']);
            
            // Add a demo delivery location for the trip - this is the key change to fix the empty map
            if ($trip && $order->shop) {
                $shop = $order->shop;
                $trip->locations()->create([
                    'address' => $shop->address ?? 'Delivery destination', 
                    'lat' => $shop->location['lat'] ?? ($shop->location['latitude'] ?? 0),
                    'lng' => $shop->location['lng'] ?? ($shop->location['longitude'] ?? 0),
                    'sequence' => 0,
                    'eta_minutes' => 30,
                    'status' => 'pending'
                ]);
            }
        } catch (Throwable $e) {
            // Log the error but don't stop order creation
            Log::error('Error creating trip for order #' . $order->id . ': ' . $e->getMessage());
        }

        // (new OrderNotificationService)->sendOrderNotification($order, 'created');

        (new ModelLogService)->logging($order, $order->getAttributes(), 'created');
    }

    /**
     * Handle the Brand "updated" event.
     *
     * @param Order $order
     * @return void
     */
    public function updated(Order $order): void
    {
        if ($order->status === Order::STATUS_READY && empty($order->deliveryman) && $this->autoDeliveryMan()) {
			AttachDeliveryMan::dispatchAfterResponse($order, $this->language());
        }   

        // (new OrderNotificationService)->sendOrderNotification($order, 'updated');

        (new ModelLogService)->logging($order, $order->getAttributes(), 'updated');
    }

    /**
     * Handle the Order "restored" event.
     *
     * @param Order $order
     * @return void
     */
    public function deleted(Order $order): void
    {
		try {
			$order->transactions()->delete();
			$order->reviews()->delete();
			$order->galleries()->delete();
			$order->coupon()->delete();
			$order->pointHistories()->delete();
			$order->orderDetails()->delete();
			DB::table('push_notifications')
				->where(function ($query) {
					$query
						->where('type', PushNotification::NEW_ORDER)
						->orWhere('type', PushNotification::STATUS_CHANGED);
				})
				->where('title', $order->id)
				->delete();
		} catch (Throwable|InvalidArgumentException) {}

        (new ModelLogService)->logging($order, $order->getAttributes(), 'deleted');
    }

    /**
     * Handle the Order "restored" event.
     *
     * @param Order $order
     * @return void
     */
    public function restored(Order $order): void
    {
        (new ModelLogService)->logging($order, $order->getAttributes(), 'restored');
    }

    /**
     * @return string
     */
    public function language(): string
    {
        return request(
            'lang',
            data_get(Language::where('default', 1)->first(['locale', 'default']), 'locale')
        );
    }

    /**
     * @return bool
     */
    public function autoDeliveryMan(): bool
    {
        $autoDeliveryMan = Settings::where('key', 'order_auto_delivery_man')->first();

        return (int)data_get($autoDeliveryMan, 'value', 0) === 1;
    }

}
