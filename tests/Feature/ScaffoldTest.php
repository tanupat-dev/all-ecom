<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Auth\Pages\Login;
use Filament\Support\Facades\FilamentTimezone;
use Livewire\Livewire;

it('responds to the health check', function () {
    $this->get('/up')->assertOk();
});

it('renders the admin login page', function () {
    $this->get('/admin/login')->assertOk();
});

it('authenticates the seeded admin into the panel', function () {
    $this->seed(DatabaseSeeder::class);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'admin@all-ecom.test',
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs(User::query()->firstWhere('email', 'admin@all-ecom.test'));
});

it('stores time in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');
});

it('displays time in Asia/Bangkok in the panel', function () {
    expect(FilamentTimezone::get())->toBe('Asia/Bangkok');
});
