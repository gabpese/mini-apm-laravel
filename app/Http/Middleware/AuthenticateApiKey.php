<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    /**
     * Authenticate the request by the project API key, sent as a Bearer token
     * or in the X-API-Key header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = self::plainKey($request);

        $apiKey = $plain === null ? null : ApiKey::findActive($plain);

        if ($apiKey === null) {
            return response()->json(['message' => 'Invalid or missing API key.'], 401);
        }

        // Skip the write when the key was used in the last minute.
        if ($apiKey->last_used_at === null || $apiKey->last_used_at->lt(now()->subMinute())) {
            $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    public static function plainKey(Request $request): ?string
    {
        $key = $request->bearerToken() ?? $request->header('X-API-Key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
