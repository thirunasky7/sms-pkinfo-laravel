<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceWhatsAppSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $owner = $user && $user->role === 'sub_admin' && $user->parent ? $user->parent : $user;
        $subscription = $owner?->activeSubscription()->with('plan')->first();

        if (! $subscription) {
            return response()->json(['message' => 'No active subscription'], 402);
        }

        if (! $subscription->allowsWhatsApp()) {
            return response()->json([
                'message' => 'WhatsApp limit reached or subscription expired',
                'whatsapp_used' => $subscription->whatsapp_used,
                'whatsapp_limit' => $subscription->plan->whatsapp_limit,
            ], 402);
        }

        $request->attributes->set('subscription', $subscription);

        return $next($request);
    }
}
