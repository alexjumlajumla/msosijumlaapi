<?php
// Script to add sample trip locations to existing trips with no locations

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Trip;
use App\Models\TripLocation;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

echo "Starting trip locations fix...\n";

// Get all trips without locations
$trips = Trip::whereDoesntHave('locations')->get();
echo "Found " . count($trips) . " trips without locations\n";

foreach ($trips as $trip) {
    echo "Processing trip #{$trip->id}...\n";
    
    // Find the associated order
    $orderTrip = DB::table('order_trips')->where('trip_id', $trip->id)->first();
    
    if ($orderTrip) {
        $order = Order::find($orderTrip->order_id);
        
        if ($order && $order->shop) {
            $shop = $order->shop;
            
            // Create a location for this trip
            $location = new TripLocation();
            $location->trip_id = $trip->id;
            $location->address = $shop->address ?? 'Delivery destination';
            $location->lat = $shop->location['lat'] ?? ($shop->location['latitude'] ?? 0); 
            $location->lng = $shop->location['lng'] ?? ($shop->location['longitude'] ?? 0);
            $location->sequence = 0;
            $location->eta_minutes = 30;
            $location->status = 'pending';
            $location->save();
            
            echo "  ✓ Created location for order #{$order->id} (trip #{$trip->id})\n";
        } else {
            echo "  ✗ No shop found for order #{$orderTrip->order_id}\n";
            
            // Create a dummy location with random coordinates near the trip start point
            $lat = $trip->start_lat + (mt_rand(-100, 100) / 1000);
            $lng = $trip->start_lng + (mt_rand(-100, 100) / 1000);
            
            $location = new TripLocation();
            $location->trip_id = $trip->id;
            $location->address = 'Delivery destination';
            $location->lat = $lat;
            $location->lng = $lng;
            $location->sequence = 0;
            $location->eta_minutes = 30;
            $location->status = 'pending';
            $location->save();
            
            echo "  ✓ Created dummy location for trip #{$trip->id}\n";
        }
    } else {
        echo "  ✗ No associated order found for trip #{$trip->id}\n";
    }
}

echo "Completed!\n"; 