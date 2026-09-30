<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-API-Key');
        $secret = $request->header('X-API-Secret');

        if (! $key || ! $secret) {
            return response()->json(['message' => 'API credentials required'], 401);
        }

        $apiKey = ApiKey::query()
            ->where('key', $key)
            ->whereNull('revoked_at')
            ->first();

        if (! $apiKey || ! Hash::check($secret, $apiKey->secret_hash)) {
            return response()->json(['message' => 'Invalid API credentials'], 401);
        }

        if ($apiKey->user->status !== 'active') {
            return response()->json(['message' => 'Account suspended'], 403);
        }

        $request->attributes->set('api_key', $apiKey);
        $request->setUserResolver(fn () => $apiKey->user);

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
