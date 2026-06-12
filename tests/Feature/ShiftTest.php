<?php

use App\Actions\Pos\CloseShift;
use App\Actions\Pos\OpenShift;
use App\Actions\Pos\RecordCashMovement;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Authorization\PermissionCatalogue;
use App\Enums\CashMovementType;
use App\Enums\Platform;
use App\Enums\ShiftStatus;
use App\Models\Location;
use App\Models\Register;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);

    $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
    $cashier->assignRole('Cashier');
    actingAs($cashier);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function aRegister(): Register
{
    app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, Location::query()->firstOrFail());

    return Register::query()->firstOrFail();
}

it('opens a Shift with its counted opening float', function () {
    $shift = app(OpenShift::class)->handle(aRegister(), Money::fromBaht('1000'));

    expect($shift->status)->toBe(ShiftStatus::Open)
        ->and($shift->opening_float?->satang)->toBe(100000)
        ->and($shift->created_by)->toBe(auth()->id());
});

it('allows at most one open Shift per Register', function () {
    $register = aRegister();
    app(OpenShift::class)->handle($register, Money::fromBaht('1000'));

    app(OpenShift::class)->handle($register, Money::fromBaht('500'));
})->throws(LogicException::class, 'already has an open Shift');

it('refuses opening a Shift without the pos.open_shift permission', function () {
    $register = aRegister();
    actingAs(User::factory()->create(['tenant_id' => app(TenantContext::class)->current()?->id]));

    app(OpenShift::class)->handle($register, Money::fromBaht('1000'));
})->throws(AuthorizationException::class);

it('records Paid-in and Paid-out on the open Shift', function () {
    $shift = app(OpenShift::class)->handle(aRegister(), Money::fromBaht('1000'));

    app(RecordCashMovement::class)->handle($shift, CashMovementType::PaidIn, Money::fromBaht('200'), 'แลกเหรียญ');
    app(RecordCashMovement::class)->handle($shift, CashMovementType::PaidOut, Money::fromBaht('50.25'), 'ค่าน้ำแข็ง');

    expect($shift->cashMovements()->count())->toBe(2);
});

it('refuses a cash movement on a closed Shift', function () {
    $shift = app(OpenShift::class)->handle(aRegister(), Money::fromBaht('1000'));
    app(CloseShift::class)->handle($shift, Money::fromBaht('1000'));

    app(RecordCashMovement::class)->handle($shift, CashMovementType::PaidIn, Money::fromBaht('10'), 'สาย');
})->throws(LogicException::class, 'open Shift');

it('closes blind: expected_cash derives from float + paid-in − paid-out, over_short = counted − expected', function () {
    $shift = app(OpenShift::class)->handle(aRegister(), Money::fromBaht('1000'));
    app(RecordCashMovement::class)->handle($shift, CashMovementType::PaidIn, Money::fromBaht('200'), 'แลกเหรียญ');
    app(RecordCashMovement::class)->handle($shift, CashMovementType::PaidOut, Money::fromBaht('50'), 'ค่าน้ำแข็ง');

    app(CloseShift::class)->handle($shift, Money::fromBaht('1100'));
    $shift->refresh();

    expect($shift->status)->toBe(ShiftStatus::Closed)
        ->and($shift->expected_cash?->satang)->toBe(115000)
        ->and($shift->counted_cash?->satang)->toBe(110000)
        ->and($shift->over_short?->satang)->toBe(-5000) // short ฿50
        ->and($shift->closed_at)->not->toBeNull();
});

it('refuses closing a Shift twice', function () {
    $shift = app(OpenShift::class)->handle(aRegister(), Money::fromBaht('1000'));
    app(CloseShift::class)->handle($shift, Money::fromBaht('1000'));

    app(CloseShift::class)->handle($shift, Money::fromBaht('1000'));
})->throws(LogicException::class, 'open Shift');

it('passes the cross-tenant isolation harness (shifts)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): Shift {
        $sequence++;
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        app(CreateShop::class)->handle("หน้าร้าน {$sequence}", Platform::Pos, $location);

        // The harness creates bare tenants (no default roles) — build one.
        PermissionCatalogue::ensureSeeded();
        $role = Role::findOrCreate('Cashier', 'web');
        $role->givePermissionTo('pos.open_shift');

        $cashier = User::factory()->create(['tenant_id' => app(TenantContext::class)->current()?->id]);
        $cashier->assignRole($role);
        actingAs($cashier);

        return app(OpenShift::class)->handle(Register::query()->firstOrFail(), Money::fromBaht('100'));
    });
});
