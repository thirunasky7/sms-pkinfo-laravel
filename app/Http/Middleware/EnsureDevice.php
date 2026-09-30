<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();

        if (! $device instanceof Device) {
            return response()->json(['message' => 'Device token required'], 401);
        }

        if (in_array($device->status, ['disabled', 'paused'], true)) {
            return response()->json(['message' => 'Device disabled'], 403);
        }

        $device->forceFill([
            'status' => 'online',
            'last_sync_at' => now(),
        ])->saveQuietly();

        return $next($request);
    }
}
