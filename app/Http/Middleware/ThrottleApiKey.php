<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->attributes->get('api_key');
        $limit = $apiKey?->rate_limit ?? 60;
        $key = 'api-key:'.($apiKey->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json(['message' => 'Too many requests'], 429)
                ->header('Retry-After', RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
