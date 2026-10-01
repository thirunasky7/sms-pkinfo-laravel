<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DevicePairingToken;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\Webhook;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerAdminController extends Controller
{
    protected function accountId(): int
    {
        return Auth::user()->ownsAccountId();
    }

    public function dashboard()
    {
        $accountId = $this->accountId();
        $subscription = Auth::user()->activeSubscription()->with('plan')->first()
            ?? Device::where('user_id', $accountId)->first()?->user?->activeSubscription()->with('plan')->first();

        // Prefer account owner's subscription
        $owner = Auth::user()->role === 'sub_admin'
            ? Auth::user()->parent
            : Auth::user();
        $subscription = $owner->activeSubscription()->with('plan')->first();

        return view('admin.customer.dashboard', [
            'devices' => Device::where('user_id', $accountId)->count(),
            'online' => Device::where('user_id', $accountId)->where('status', 'online')->count(),
            'sentToday' => Message::where('user_id', $accountId)->where('direction', 'outgoing')->whereDate('created_at', today())->count(),
            'receivedToday' => Message::where('user_id', $accountId)->where('direction', 'incoming')->whereDate('created_at', today())->count(),
            'subscription' => $subscription,
            'recentMessages' => Message::where('user_id', $accountId)->latest()->limit(10)->get(),
        ]);
    }

    public function devices()
    {
        $devices = Device::where('user_id', $this->accountId())->latest()->get();

        return view('admin.customer.devices', compact('devices'));
    }

    public function createPairing(Request $request)
    {
        $token = Str::random(32);
        $pairing = DevicePairingToken::create([
            'user_id' => $this->accountId(),
            'token' => $token,
            'expires_at' => now()->addMinutes(15),
        ]);

        $payload = json_encode([
            'token' => $pairing->token,
            'api' => rtrim(config('app.url'), '/').'/api/v1',
        ]);

        return view('admin.customer.pairing', [
            'pairing' => $pairing,
            'payload' => $payload,
            'qrUrl' => 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data='.urlencode($payload),
        ]);
    }

    public function renameDevice(Request $request, Device $device)
    {
        abort_unless($device->user_id === $this->accountId(), 403);

        $data = $request->validate(['name' => 'required|string|max:255']);
        $device->update(['name' => $data['name']]);

        return back()->with('success', 'Device renamed.');
    }

    public function removeDevice(Device $device)
    {
        abort_unless($device->user_id === $this->accountId(), 403);
        $device->tokens()->delete();
        $device->delete();

        return back()->with('success', 'Device removed.');
    }

    public function messages(Request $request)
    {
        $messages = Message::where('user_id', $this->accountId())
            ->when($request->direction, fn ($q) => $q->where('direction', $request->direction))
            ->latest()
            ->paginate(40);

        return view('admin.customer.messages', compact('messages'));
    }

    public function compose()
    {
        $devices = Device::where('user_id', $this->accountId())
            ->whereNotIn('status', ['disabled'])
            ->get();

        return view('admin.customer.compose', compact('devices'));
    }

    public function sendSms(Request $request)
    {
        $data = $request->validate([
            'to' => 'required|string|max:32',
            'body' => 'required|string|max:1600',
            'device_id' => 'nullable|exists:devices,id',
            'scheduled_at' => 'nullable|date',
        ]);

        $apiRequest = Request::create('/api/v1/messages/send', 'POST', $data);
        $apiRequest->setUserResolver(fn () => Auth::user());

        // Reuse service logic inline for web
        $controller = app(\App\Http\Controllers\Api\V1\MessageController::class);
        $subscription = Auth::user()->role === 'sub_admin'
            ? Auth::user()->parent->activeSubscription()->with('plan')->first()
            : Auth::user()->activeSubscription()->with('plan')->first();

        if (! $subscription || ! $subscription->allowsSend()) {
            return back()->withErrors(['subscription' => 'Subscription limit reached or inactive.']);
        }

        $apiRequest->attributes->set('subscription', $subscription);
        $response = $controller->send($apiRequest);

        if ($response->getStatusCode() >= 400) {
            $payload = $response->getData(true);

            return back()->withErrors(['send' => $payload['message'] ?? 'Send failed']);
        }

        return back()->with('success', 'SMS queued.');
    }

    public function bulkUpload(Request $request)
    {
        $request->validate([
            'csv' => 'required|file|mimes:csv,txt',
            'device_id' => 'nullable|exists:devices,id',
        ]);

        $handle = fopen($request->file('csv')->getRealPath(), 'r');
        $header = fgetcsv($handle);
        $queued = 0;

        $subscription = Auth::user()->role === 'sub_admin'
            ? Auth::user()->parent->activeSubscription()->with('plan')->first()
            : Auth::user()->activeSubscription()->with('plan')->first();

        while (($row = fgetcsv($handle)) !== false) {
            if (! $subscription || ! $subscription->allowsSend()) {
                break;
            }

            $to = $row[0] ?? null;
            $body = $row[1] ?? null;
            if (! $to || ! $body) {
                continue;
            }

            $apiRequest = Request::create('/api/v1/messages/send', 'POST', [
                'to' => $to,
                'body' => $body,
                'device_id' => $request->device_id,
            ]);
            $apiRequest->setUserResolver(fn () => Auth::user());
            $apiRequest->attributes->set('subscription', $subscription);

            $controller = app(\App\Http\Controllers\Api\V1\MessageController::class);
            $response = $controller->send($apiRequest);
            if ($response->getStatusCode() < 300) {
                $queued++;
                $subscription->refresh();
            }
        }

        fclose($handle);

        return back()->with('success', "Queued {$queued} messages.");
    }

    public function apiIntegration()
    {
        $accountId = $this->accountId();
        $keys = ApiKey::where('user_id', $accountId)->latest()->get();
        $webhooks = Webhook::where('user_id', $accountId)->latest()->get();

        return view('admin.customer.api', compact('keys', 'webhooks'));
    }

    public const API_KEY_ACCESS = [
        'full' => null,
        'sms' => ['messages:send', 'messages:read'],
        'whatsapp' => ['whatsapp:send', 'whatsapp:read', 'whatsapp:templates'],
    ];

    public function createApiKey(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100',
            'access' => 'nullable|in:'.implode(',', array_keys(self::API_KEY_ACCESS)),
        ]);

        $secret = Str::random(40);
        $apiKey = ApiKey::create([
            'user_id' => $this->accountId(),
            'name' => $data['name'] ?? 'Default',
            'key' => 'smk_'.Str::random(24),
            'secret_hash' => Hash::make($secret),
            'secret_encrypted' => $secret,
            'rate_limit' => 60,
            'scopes' => self::API_KEY_ACCESS[$data['access'] ?? 'full'],
        ]);

        Audit::log((int) Auth::id(), 'api_key.created', $apiKey, ['name' => $apiKey->name]);

        return back()
            ->with('success', 'API key created.')
            ->with('new_api_credentials', ['name' => $apiKey->name, 'key' => $apiKey->key, 'secret' => $secret]);
    }

    public function regenerateApiSecret(ApiKey $apiKey)
    {
        abort_unless((int) $apiKey->user_id === $this->accountId() && $apiKey->isActive(), 403);

        $secret = Str::random(40);
        $apiKey->update(['secret_hash' => Hash::make($secret), 'secret_encrypted' => $secret]);

        Audit::log((int) Auth::id(), 'api_key.secret_regenerated', $apiKey, ['name' => $apiKey->name]);

        return back()
            ->with('success', 'New secret generated. The old secret stopped working immediately.')
            ->with('new_api_credentials', ['name' => $apiKey->name, 'key' => $apiKey->key, 'secret' => $secret]);
    }

    public function showApiSecret(ApiKey $apiKey)
    {
        abort_unless((int) $apiKey->user_id === $this->accountId(), 403);

        if (! $apiKey->isActive() || ! $apiKey->hasViewableSecret()) {
            return response()->json([
                'message' => $apiKey->isActive()
                    ? 'This key was created before secrets could be viewed. Regenerate the secret to see it.'
                    : 'This key is revoked.',
            ], 404);
        }

        Audit::log((int) Auth::id(), 'api_key.secret_viewed', $apiKey, ['name' => $apiKey->name]);

        return response()
            ->json(['key' => $apiKey->key, 'secret' => $apiKey->secret_encrypted])
            ->header('Cache-Control', 'no-store');
    }

    public function revokeApiKey(ApiKey $apiKey)
    {
        abort_unless((int) $apiKey->user_id === $this->accountId(), 403);

        if ($apiKey->isActive()) {
            $apiKey->update(['revoked_at' => now(), 'secret_encrypted' => null]);
            Audit::log((int) Auth::id(), 'api_key.revoked', $apiKey, ['name' => $apiKey->name]);
        }

        return back()->with('success', 'API key revoked.');
    }

    public function storeWebhook(Request $request)
    {
        $data = $request->validate([
            'event_type' => 'required|string',
            'url' => 'required|url',
        ]);

        Webhook::create([
            'user_id' => $this->accountId(),
            'event_type' => $data['event_type'],
            'url' => $data['url'],
            'secret' => Str::random(32),
            'is_active' => true,
        ]);

        return back()->with('success', 'Webhook saved.');
    }

    public function tickets()
    {
        $tickets = SupportTicket::where('user_id', $this->accountId())->latest()->get();

        return view('admin.customer.tickets', compact('tickets'));
    }

    public function storeTicket(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'priority' => 'nullable|in:low,normal,high,urgent',
        ]);

        SupportTicket::create([
            'user_id' => $this->accountId(),
            'subject' => $data['subject'],
            'body' => $data['body'],
            'priority' => $data['priority'] ?? 'normal',
        ]);

        return back()->with('success', 'Ticket submitted.');
    }
}
