<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSubscriptionLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $subscription = $user?->activeSubscription()->with('plan')->first();

        if (! $subscription) {
            return response()->json(['message' => 'No active subscription'], 402);
        }

        if ($subscription->expires_at->isPast() && $subscription->status === 'active') {
            $subscription->update([
                'status' => 'grace',
                'grace_ends_at' => $subscription->expires_at->copy()->addDays(3),
            ]);
            $subscription->refresh();
        }

        if (! $subscription->allowsSend()) {
            return response()->json([
                'message' => 'SMS limit reached or subscription expired',
                'sms_used' => $subscription->sms_used,
                'sms_limit' => $subscription->plan->sms_limit,
            ], 402);
        }

        $request->attributes->set('subscription', $subscription);

        return $next($request);
    }
}
