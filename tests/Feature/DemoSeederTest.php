<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

it('creates the demo account, already verified, when it is configured', function () {
    config(['services.demo.user' => ['email' => 'demo@example.com', 'password' => 'a-demo-password']]);

    $this->seed(DatabaseSeeder::class);

    $user = User::sole();
    expect($user->email)->toBe('demo@example.com')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('a-demo-password', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('a-demo-password');

    $this->post(route('login'), ['email' => 'demo@example.com', 'password' => 'a-demo-password'])
        ->assertRedirect(route('dashboard'));
});

it('can run again without duplicating or changing the account', function () {
    config(['services.demo.user' => ['email' => 'demo@example.com', 'password' => 'a-demo-password']]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(1);
});

it('creates nothing when the demo account is not configured', function () {
    config(['services.demo.user' => ['email' => null, 'password' => null]]);

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0);
});
