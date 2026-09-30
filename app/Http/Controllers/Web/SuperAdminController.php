<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Message;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SuperAdminController extends Controller
{
    public function dashboard()
    {
        return view('admin.super.dashboard', [
            'customers' => User::where('role', 'customer_admin')->count(),
            'devices' => Device::count(),
            'sent' => Message::where('direction', 'outgoing')->count(),
            'received' => Message::where('direction', 'incoming')->count(),
            'onlineDevices' => Device::where('status', 'online')->count(),
            'recentMessages' => Message::with(['user', 'device'])->latest()->limit(10)->get(),
        ]);
    }

    public function users()
    {
        $users = User::whereIn('role', ['customer_admin', 'sub_admin'])
            ->with('activeSubscription.plan')
            ->latest()
            ->paginate(20);

        $plans = SubscriptionPlan::where('is_active', true)->get();

        return view('admin.super.users', compact('users', 'plans'));
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'company' => 'nullable|string|max:255',
            'plan_id' => 'nullable|exists:subscription_plans,id',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'customer_admin',
            'status' => 'active',
            'company' => $data['company'] ?? null,
        ]);

        if (! empty($data['plan_id'])) {
            $plan = SubscriptionPlan::findOrFail($data['plan_id']);
            $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'expires_at' => now()->addDays($plan->duration_days),
                'status' => 'active',
            ]);
        }

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'user.created',
            'target_type' => User::class,
            'target_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'Customer created.');
    }

    public function updateUserStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        $user->update(['status' => $data['status']]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'user.status_updated',
            'target_type' => User::class,
            'target_id' => $user->id,
            'meta' => $data,
            'ip' => $request->ip(),
        ]);

        return back()->with('success', 'User status updated.');
    }

    public function devices()
    {
        $devices = Device::with('user')->latest()->paginate(30);

        return view('admin.super.devices', compact('devices'));
    }

    public function updateDeviceStatus(Request $request, Device $device)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['online', 'offline', 'paused', 'disabled'])],
        ]);

        $device->update(['status' => $data['status']]);

        return back()->with('success', 'Device updated.');
    }

    public function messages(Request $request)
    {
        $messages = Message::with(['user', 'device'])
            ->when($request->direction, fn ($q) => $q->where('direction', $request->direction))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(50);

        return view('admin.super.messages', compact('messages'));
    }

    public function plans()
    {
        $plans = SubscriptionPlan::latest()->get();

        return view('admin.super.plans', compact('plans'));
    }

    public function storePlan(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sms_limit' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
        ]);

        SubscriptionPlan::create($data + ['is_active' => true, 'currency' => 'INR']);

        return back()->with('success', 'Plan created.');
    }
}
