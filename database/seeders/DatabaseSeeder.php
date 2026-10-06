<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Creates the account used by a demo, only when DEMO_USER_EMAIL and
     * DEMO_USER_PASSWORD are set. Nothing is created otherwise.
     */
    public function run(): void
    {
        $email = config('services.demo.user.email');
        $password = config('services.demo.user.password');

        if (! $email || ! $password) {
            return;
        }

        // forceFill: the verification date is not mass assignable. The password is hashed by the model cast.
        User::query()->firstOrNew(['email' => $email])
            ->forceFill(['name' => 'Demo User', 'password' => $password, 'email_verified_at' => now()])
            ->save();
    }
}
