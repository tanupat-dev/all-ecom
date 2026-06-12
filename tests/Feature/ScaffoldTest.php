<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Auth\Pages\Login;
use Filament\Support\Facades\FilamentTimezone;
use Livewire\Livewire;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\get;
use function Pest\Laravel\seed;

it('responds to the health check', function () {
    get('/up')->assertOk();
});

it('renders the admin login page', function () {
    get('/admin/login')->assertOk();
});

it('authenticates the seeded admin into the panel', function () {
    seed(DatabaseSeeder::class);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'admin@all-ecom.test',
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    assertAuthenticatedAs(User::query()->where('email', 'admin@all-ecom.test')->firstOrFail());
});

it('stores time in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');
});

it('displays time in Asia/Bangkok in the panel', function () {
    expect(FilamentTimezone::get())->toBe('Asia/Bangkok');
});
