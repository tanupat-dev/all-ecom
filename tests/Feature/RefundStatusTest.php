<?php

use App\Actions\Returns\DeriveRefundStatus;
use App\Actions\Returns\UpsertReturn;
use App\Actions\Tenants\CreateTenant;
use App\Enums\RefundStatus;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Imports\NormalizedReturn;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\Money;
use App\Tenancy\TenantContext;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/**
 * importShop()/returnableOrder() come from the Phase-4/5 test files.
 *
 * @param  list<array{order_line: OrderLine, qty: int}>|null  $lines
 */
function refundCase(Order $order, string $id, ReturnSubStatus $subStatus, ?DateTimeImmutable $refundedAt, ?array $lines = null): void
{
    app(UpsertReturn::class)->handle($order->shop()->firstOrFail(), new NormalizedReturn(
        platformReturnId: $id,
        returnType: ReturnType::ReturnAndRefund,
        subStatus: $subStatus,
        lines: $lines ?? [['order_line' => $order->lines->firstOrFail(), 'qty' => 1]],
        refundAmount: Money::fromBaht('159'),
        refundedAt: $refundedAt,
    ));
}

it('rolls up ไม่มี when the Order has no Returns', function () {
    $order = returnableOrder(importShop());

    expect(app(DeriveRefundStatus::class)->handle($order))->toBe(RefundStatus::None);
});

it('rolls up รอคืน while a Return is in flight, unrefunded', function () {
    $order = returnableOrder(importShop());
    refundCase($order, 'RET-10', ReturnSubStatus::AwaitingBuyerShipment, null);

    expect(app(DeriveRefundStatus::class)->handle($order))->toBe(RefundStatus::Pending);
});

it('rolls up คืนบางส่วน when some but not all quantities were refunded', function () {
    $order = returnableOrder(importShop());
    // Order has 2×M + 1×L; refund 1×M only.
    refundCase($order, 'RET-11', ReturnSubStatus::Received, new DateTimeImmutable('2026-06-11 10:00:00'));

    expect(app(DeriveRefundStatus::class)->handle($order))->toBe(RefundStatus::Partial);
});

it('rolls up คืนเต็มจำนวน when refunds cover every line and quantity', function () {
    $order = returnableOrder(importShop());
    $lines = array_values($order->lines
        ->map(fn (OrderLine $line): array => ['order_line' => $line, 'qty' => $line->qty])
        ->all());
    refundCase($order, 'RET-12', ReturnSubStatus::Received, new DateTimeImmutable('2026-06-11 10:00:00'), $lines);

    expect(app(DeriveRefundStatus::class)->handle($order))->toBe(RefundStatus::Full);
});

it('ignores a Platform-closed Return — ยกเลิกการคืน refunded nothing', function () {
    $order = returnableOrder(importShop());
    refundCase($order, 'RET-13', ReturnSubStatus::Closed, null);

    expect(app(DeriveRefundStatus::class)->handle($order))->toBe(RefundStatus::None);
});
