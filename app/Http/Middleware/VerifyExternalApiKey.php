<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyExternalApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-KEY');
        $expectedKey = env('EXTERNAL_APP_API_KEY', 'electropoint_secret_key_2026');

        if (!$apiKey || !hash_equals($expectedKey, $apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado: API Key inválida o no proporcionada.'
            ], 401);
        }

        return $next($request);
    }
}
