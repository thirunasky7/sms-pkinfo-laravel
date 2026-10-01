<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiKeyScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $apiKey = $request->attributes->get('api_key');

        if (! $apiKey) {
            return response()->json(['message' => 'API credentials required'], 401);
        }

        foreach ($scopes as $scope) {
            if (! $apiKey->hasScope($scope)) {
                return response()->json([
                    'message' => 'API key is missing the required scope',
                    'required_scope' => $scope,
                ], 403);
            }
        }

        return $next($request);
    }
}
