<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $super = User::updateOrCreate(
            ['email' => 'admin@sms-gateway.test'],
            [
                'name' => 'Super Admin',
                'password' => 'password',
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $customer = User::updateOrCreate(
            ['email' => 'customer@sms-gateway.test'],
            [
                'name' => 'Demo Customer',
                'password' => 'password',
                'role' => 'customer_admin',
                'status' => 'active',
                'company' => 'Demo Co',
            ]
        );

        $starter = SubscriptionPlan::updateOrCreate(
            ['name' => 'Starter'],
            [
                'sms_limit' => 1000,
                'price' => 999,
                'currency' => 'INR',
                'duration_days' => 30,
                'is_active' => true,
            ]
        );

        SubscriptionPlan::updateOrCreate(
            ['name' => 'Growth'],
            [
                'sms_limit' => 10000,
                'price' => 4999,
                'currency' => 'INR',
                'duration_days' => 30,
                'is_active' => true,
            ]
        );

        if (! $customer->subscriptions()->where('status', 'active')->exists()) {
            $customer->subscriptions()->create([
                'plan_id' => $starter->id,
                'starts_at' => now(),
                'expires_at' => now()->addDays(30),
                'status' => 'active',
                'sms_used' => 0,
            ]);
        }
    }
}
