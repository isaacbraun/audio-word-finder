<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Laravel\Cashier\Subscription;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Isaac Test',
            'email' => 'isaac@braunbauen.com',
            'password' => 'rdg@CDF@ncm7jqx4hrd',
            'stripe_id' => 'cus_xxxxx',
        ]);

        // Create subscription record directly
        Subscription::create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_xxxxx', // Fake subscription ID
            'stripe_status' => 'active',
            'stripe_price' => 'price_H5ggYwtDq4fbrJ',
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
        ]);
    }
}
