<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Http\Controllers\API\v1\Dashboard\Admin\AdminBaseController;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\TripService\RouteOptimizer;

class TripController extends AdminBaseController
{
    public function index(): JsonResponse
    {
        $trips = Trip::with('locations')->latest()->paginate(20);
        return $this->successResponse('success', $trips);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string',
            'start_address' => 'required|string',
            'start_lat' => 'required|numeric',
            'start_lng' => 'required|numeric',
            'vehicle_id' => 'nullable|exists:delivery_vehicles,id',
            'driver_id' => 'nullable|exists:users,id',
            'scheduled_at' => 'nullable|date',
            'locations' => 'required|array|min:1',
            'locations.*.address' => 'required|string',
            'locations.*.lat' => 'required|numeric',
            'locations.*.lng' => 'required|numeric',
        ]);

        $trip = Trip::create($data);
        foreach ($data['locations'] as $idx => $loc) {
            $trip->locations()->create([
                'address' => $loc['address'],
                'lat' => $loc['lat'],
                'lng' => $loc['lng'],
                'sequence' => $idx,
            ]);
        }

        return $this->successResponse('created', $trip->load('locations'));
    }

    public function optimize(Request $request, Trip $trip, RouteOptimizer $optimizer): JsonResponse
    {
        $order = $optimizer->optimise($trip);

        // Update sequences if we got a valid order
        foreach ($order as $seq => $locId) {
            $trip->locations()->where('id', $locId)->update(['sequence' => $seq]);
        }

        // Save optimisation meta
        $trip->update(['meta' => array_merge($trip->meta ?? [], ['optimized_at' => now()])]);

        return $this->successResponse('optimized', $trip->load('locations'));
    }

    public function show(Trip $trip): JsonResponse
    {
        return $this->successResponse('success', $trip->load(['locations','driver','vehicle']));
    }
} 