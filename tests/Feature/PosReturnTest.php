<?php

use App\Actions\Pos\CheckoutPosSale;
use App\Actions\Pos\CloseShift;
use App\Actions\Pos\RefundPosSale;
use App\Actions\Tenants\CreateTenant;
use App\Enums\TenderType;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Variant;
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

function cashSale(Variant $variant, int $qty, string $tenderBaht): Order
{
    return app(CheckoutPosSale::class)->handle(
        Shift::query()->firstOrFail(),
        [['variant' => $variant, 'qty' => $qty]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht($tenderBaht)]],
    );
}

/**
 * @return array{int, int} [on_hand, damaged]
 */
function poolsOf(Variant $variant): array
{
    $balance = StockBalance::query()->where('variant_id', $variant->id)->first();

    return [$balance->on_hand ?? 0, $balance->damaged ?? 0];
}

it('refunds a sale as a linked negative order: stock back, cash out, audited', function () {
    openPosShift();
    $variant = posVariant('RET-1', '150');
    $sale = cashSale($variant, 2, '300'); // on_hand 10 → 8

    $refund = app(RefundPosSale::class)->handle($sale, [
        ['line' => $sale->lines->firstOrFail(), 'qty' => 2],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('300')],
    ]);

    expect($refund->ref_order_id)->toBe($sale->id)
        ->and($refund->total?->satang)->toBe(-30000)
        ->and($refund->lines->first()?->qty)->toBe(-2)
        ->and($refund->receipt_no)->toBe(2)
        ->and(poolsOf($variant))->toBe([10, 0])
        ->and((int) $refund->payments()->sum('amount'))->toBe(-30000)
        ->and(AuditLog::query()->where('action', 'sale.refund')->count())->toBe(1);
});

it('routes a damaged return to the Damaged pool, not On-Hand', function () {
    openPosShift();
    $variant = posVariant('RET-1', '150');
    $sale = cashSale($variant, 1, '150'); // on_hand 10 → 9

    app(RefundPosSale::class)->handle($sale, [
        ['line' => $sale->lines->firstOrFail(), 'qty' => 1, 'damaged' => true],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('150')],
    ]);

    expect(poolsOf($variant))->toBe([9, 1]);
});

it('never lets returned qty exceed what remains unreturned', function () {
    openPosShift();
    $variant = posVariant('RET-1', '100');
    $sale = cashSale($variant, 2, '200');

    app(RefundPosSale::class)->handle($sale, [
        ['line' => $sale->lines->firstOrFail(), 'qty' => 1],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')],
    ]);

    app(RefundPosSale::class)->handle($sale, [
        ['line' => $sale->lines->firstOrFail(), 'qty' => 2],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('200')],
    ]);
})->throws(InvalidArgumentException::class, 'exceeds what remains');

it('prorates a line discount on partial return, rounding DOWN — never refund more than received', function () {
    openPosShift();
    $variant = posVariant('RET-1', '100');
    $sale = app(CheckoutPosSale::class)->handle(
        Shift::query()->firstOrFail(),
        [['variant' => $variant, 'qty' => 2, 'discount_baht' => Money::fromBaht('15')]], // line_total 185
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('185')]],
    );

    $refund = app(RefundPosSale::class)->handle($sale, [
        ['line' => $sale->lines->firstOrFail(), 'qty' => 1],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('92.50')],
    ]);

    // unit 10000 − floor(1500×1/2)=750 → refund 9250
    expect($refund->total?->satang)->toBe(-9250);
});

it('requires the refund tendered to equal the returned value exactly', function () {
    openPosShift();
    $variant = posVariant('RET-1', '100');
    $sale = cashSale($variant, 1, '100');

    app(RefundPosSale::class)->handle($sale, [
        ['line' => $sale->lines->firstOrFail(), 'qty' => 1],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('90')],
    ]);
})->throws(InvalidArgumentException::class, 'must equal the returned value');

it('requires sale.refund — a Cashier cannot refund alone', function () {
    openPosShift();
    $variant = posVariant('RET-1', '100');
    $sale = cashSale($variant, 1, '100');

    $cashier = User::factory()->create(['tenant_id' => app(TenantContext::class)->current()?->id]);
    $cashier->assignRole('Cashier');
    actingAs($cashier);

    app(RefundPosSale::class)->handle($sale, [
        ['line' => $sale->lines->firstOrFail(), 'qty' => 1],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')],
    ]);
})->throws(AuthorizationException::class, 'sale.refund');

it('feeds the cash refund into the blind close', function () {
    $shift = openPosShift(); // float 1000
    $variant = posVariant('RET-1', '250');
    $sale = cashSale($variant, 1, '250'); // +250 cash

    app(RefundPosSale::class)->handle($sale, [
        ['line' => $sale->lines->firstOrFail(), 'qty' => 1],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('250')],
    ]); // −250 cash

    app(CloseShift::class)->handle($shift, Money::fromBaht('1000'));

    expect($shift->refresh()->expected_cash?->satang)->toBe(100000)
        ->and($shift->over_short?->satang)->toBe(0);
});

it('settles an exchange as the refund plus a new sale', function () {
    openPosShift();
    $small = posVariant('RET-S', '100');
    $large = posVariant('RET-L', '120');
    $sale = cashSale($small, 1, '100');

    $refund = app(RefundPosSale::class)->handle($sale, [
        ['line' => $sale->lines->firstOrFail(), 'qty' => 1],
    ], [
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')],
    ]);
    $newSale = cashSale($large, 1, '120');

    expect(($newSale->total->satang ?? 0) + ($refund->total->satang ?? 0))->toBe(2000) // net ฿20 to pay
        ->and(poolsOf($small))->toBe([10, 0])
        ->and(poolsOf($large)[0])->toBe(9);
});
