<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Http\Controllers\API\v1\Dashboard\Admin\AdminBaseController;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
} 