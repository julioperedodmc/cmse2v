<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    /**
     * List user's vehicles.
     */
    public function index(Request $request)
    {
        $vehicles = $request->user()->vehicles;

        return response()->json([
            'status' => 'success',
            'data' => $vehicles
        ]);
    }

    /**
     * Register a new vehicle.
     */
    public function store(Request $request)
    {
        // Pre-clean plate for validation check (strip spaces, hyphens, non-alphanumeric, and uppercase)
        if ($request->has('plate')) {
            $cleanedPlate = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $request->input('plate')));
            $request->merge(['plate' => $cleanedPlate]);
        }

        $validated = $request->validate([
            'brand' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'plate' => ['required', 'string', 'regex:/^[0-9]{3,4}[A-Z]{3}$/'],
            'vin' => 'nullable|string|max:255',
            'battery_capacity' => 'nullable|numeric|min:0',
        ], [
            'plate.regex' => 'El formato de la placa boliviana debe ser de 3 o 4 números seguidos de 3 letras (ej. 123ABC o 1234ABC).',
        ]);

        $vehicle = $request->user()->vehicles()->create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehículo registrado exitosamente.',
            'data' => $vehicle
        ], 201);
    }

    /**
     * Delete user's vehicle.
     */
    public function destroy(Request $request, Vehicle $vehicle)
    {
        // Enforce owner deletion only
        if ($vehicle->user_id !== $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autorizado para eliminar este vehículo.'
            ], 403);
        }

        $vehicle->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Vehículo eliminado exitosamente.'
        ]);
    }

    /**
     * Update user's vehicle.
     */
    public function update(Request $request, Vehicle $vehicle)
    {
        // Enforce owner update only
        if ($vehicle->user_id !== $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autorizado para modificar este vehículo.'
            ], 403);
        }

        // Pre-clean plate for validation check (strip spaces, hyphens, non-alphanumeric, and uppercase)
        if ($request->has('plate')) {
            $cleanedPlate = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $request->input('plate')));
            $request->merge(['plate' => $cleanedPlate]);
        }

        $validated = $request->validate([
            'brand' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'plate' => ['required', 'string', 'regex:/^[0-9]{3,4}[A-Z]{3}$/'],
            'vin' => 'nullable|string|max:255',
            'battery_capacity' => 'nullable|numeric|min:0',
        ], [
            'plate.regex' => 'El formato de la placa boliviana debe ser de 3 o 4 números seguidos de 3 letras (ej. 123ABC o 1234ABC).',
        ]);

        $vehicle->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehículo actualizado exitosamente.',
            'data' => $vehicle
        ]);
    }

}
