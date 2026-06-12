<?php

use App\Actions\Pos\CheckoutPosSale;
use App\Actions\Pos\CloseShift;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\TenderType;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\StockBalance;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

use function Pest\Laravel\actingAs;

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

// openPosShift() / posVariant() live in tests/Helpers/PosHelpers.php.

it('sells: order closes สำเร็จ, ships stock, takes payment, gets receipt_no 1', function () {
    $shift = openPosShift();
    $variant = posVariant('POS-1', '150');

    $order = app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 2],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('300')],
    ]);

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->receipt_no)->toBe(1)
        ->and($order->shift_id)->toBe($shift->id)
        ->and($order->total?->satang)->toBe(30000)
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(StockBalance::query()->where('variant_id', $variant->id)->first()?->on_hand)->toBe(8)
        ->and(StockBalance::query()->where('variant_id', $variant->id)->first()?->reserved)->toBe(0);
});

it('assigns receipt numbers sequentially per pos Shop', function () {
    $shift = openPosShift();
    $variant = posVariant('POS-1', '100');
    $checkout = app(CheckoutPosSale::class);
    $tender = fn () => [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')]];

    $first = $checkout->handle($shift, [['variant' => $variant, 'qty' => 1]], $tender());
    $second = $checkout->handle($shift, [['variant' => $variant, 'qty' => 1]], $tender());

    expect([$first->receipt_no, $second->receipt_no])->toBe([1, 2]);
});

it('splits tender and computes change from cash only', function () {
    $shift = openPosShift();
    $variant = posVariant('POS-1', '250');

    $order = app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 1],
    ], [
        ['tender' => TenderType::PromptpayQr, 'amount' => Money::fromBaht('150')],
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('200')],
    ]);

    // tendered 350 − total 250 = change 100, covered by the 200 cash
    expect($order->total?->satang)->toBe(25000)
        ->and((int) Payment::query()->where('order_id', $order->id)->sum('amount'))->toBe(35000);
});

it('refuses a tender below the order total', function () {
    $shift = openPosShift();
    $variant = posVariant('POS-1', '250');

    app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 1],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('200')],
    ]);
})->throws(InvalidArgumentException::class, 'below the order total');

it('refuses change that cash cannot cover — only cash gives change', function () {
    $shift = openPosShift();
    $variant = posVariant('POS-1', '250');

    app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 1],
    ], [
        ['tender' => TenderType::PromptpayQr, 'amount' => Money::fromBaht('300')],
    ]);
})->throws(InvalidArgumentException::class, 'only cash gives change');

it('applies a % line discount rounded half-up to whole satang, gated and audited', function () {
    $shift = openPosShift();
    $variant = posVariant('POS-1', '99.99');

    $order = app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 1, 'discount_percent' => 10.0],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')],
    ]);

    // 10% of 9999 = 999.9 → 1000 satang; total 8999
    expect($order->total?->satang)->toBe(8999)
        ->and($order->lines->first()?->discount?->satang)->toBe(1000)
        ->and(AuditLog::query()->where('action', 'sale.discount')->count())->toBe(1);
});

it('applies a baht cart discount', function () {
    $shift = openPosShift();
    $variant = posVariant('POS-1', '100');

    $order = app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 3],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('280')],
    ], cartDiscount: Money::fromBaht('20'));

    expect($order->total?->satang)->toBe(28000)
        ->and($order->cart_discount?->satang)->toBe(2000);
});

it('refuses a discount from a user without sale.discount', function () {
    $shift = openPosShift();
    $variant = posVariant('POS-1', '100');

    $cashier = User::factory()->create(['tenant_id' => app(TenantContext::class)->current()?->id]);
    $cashier->assignRole('Cashier');
    actingAs($cashier);

    app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 1, 'discount_percent' => 10.0],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')],
    ]);
})->throws(AuthorizationException::class, 'sale.discount');

it('refuses checkout on a closed Shift', function () {
    $shift = openPosShift();
    $variant = posVariant('POS-1', '100');
    app(CloseShift::class)->handle($shift, Money::fromBaht('1000'));

    app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 1],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')],
    ]);
})->throws(LogicException::class, 'open Shift');

it('feeds cash sales net of change into the blind close', function () {
    $shift = openPosShift(); // float 1000
    $variant = posVariant('POS-1', '250');

    app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 1],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('300')], // change 50 → net cash in 250
    ]);
    app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 1],
    ], [
        ['tender' => TenderType::PromptpayQr, 'amount' => Money::fromBaht('250')], // non-cash: no drawer effect
    ]);

    app(CloseShift::class)->handle($shift, Money::fromBaht('1250'));
    $shift->refresh();

    expect($shift->expected_cash?->satang)->toBe(125000)
        ->and($shift->over_short?->satang)->toBe(0);
});
