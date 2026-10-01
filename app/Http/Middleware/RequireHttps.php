<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('whatsapp.require_https') && ! $request->secure()) {
            return response()->json(['message' => 'HTTPS is required'], 403);
        }

        return $next($request);
    }
}
