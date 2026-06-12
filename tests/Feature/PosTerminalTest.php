<?php

use App\Actions\Pos\CheckoutPosSale;
use App\Actions\Pos\ParkSale;
use App\Actions\Pos\VoidParkedSale;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\TenderType;
use App\Livewire\PosTerminal;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('Admin');
    actingAs($admin);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('rings a sale on the POS screen and redirects to the receipt', function () {
    openPosShift();
    posVariant('TERM-1', '150');

    Livewire::test(PosTerminal::class)
        ->set('code', 'TERM-1')
        ->call('addItem')
        ->set('code', 'TERM-1')
        ->call('addItem') // same item scanned twice → qty 2
        ->call('addTender', 'cash', '300')
        ->call('checkout')
        ->assertRedirect();

    $order = Order::query()->whereNotNull('receipt_no')->firstOrFail();

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->total?->satang)->toBe(30000)
        ->and($order->lines()->first()?->qty)->toBe(2);
});

it('shows a fail-loud message for an unknown code', function () {
    openPosShift();

    Livewire::test(PosTerminal::class)
        ->set('code', 'ไม่มีอยู่จริง')
        ->call('addItem')
        ->assertSet('cart', [])
        ->assertSee('ไม่พบสินค้า');
});

it('parks a sale touching no stock and no money, then resumes it into checkout', function () {
    openPosShift();
    posVariant('TERM-1', '150');

    $component = Livewire::test(PosTerminal::class)
        ->set('code', 'TERM-1')
        ->call('addItem')
        ->call('park');

    $parked = Order::query()->where('status', OrderStatus::PendingPayment)->firstOrFail();

    expect(StockMovement::query()->where('ref_type', $parked->getMorphClass())->where('ref_id', $parked->id)->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($component->get('cart'))->toBe([]);

    $component->call('resume', $parked->id)
        ->call('addTender', 'cash', '150')
        ->call('checkout')
        ->assertRedirect();

    $parked->refresh();

    expect($parked->status)->toBe(OrderStatus::Completed)
        ->and($parked->receipt_no)->toBe(1)
        ->and(Order::query()->count())->toBe(1); // resumed, not duplicated
});

it('voids a parked sale to ยกเลิก, audited, with no stock effect', function () {
    openPosShift();
    $variant = posVariant('TERM-1', '150');

    $parked = app(ParkSale::class)->handle(
        Shift::query()->firstOrFail(),
        [['variant' => $variant, 'qty' => 1]],
    );

    app(VoidParkedSale::class)->handle($parked);

    // The only movement is the setup RECEIVE — the void moved nothing.
    expect($parked->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(AuditLog::query()->where('action', 'sale.void')->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('renders the reprintable receipt with items, tender, and change', function () {
    openPosShift();
    $variant = posVariant('TERM-1', '150');

    $order = app(CheckoutPosSale::class)->handle(
        Shift::query()->firstOrFail(),
        [['variant' => $variant, 'qty' => 2]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('500')]],
    );

    get(route('pos.receipt', $order))
        ->assertOk()
        ->assertSee('ใบเสร็จรับเงิน')
        ->assertSee('300.00')   // total
        ->assertSee('200.00');  // change
});

it('blocks the POS screen without pos.checkout', function () {
    $outsider = User::factory()->create(['tenant_id' => app(TenantContext::class)->current()?->id]);
    actingAs($outsider);

    get('/pos')->assertForbidden();
});
