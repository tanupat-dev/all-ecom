<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Claims\FlagReturnFeeClaim;
use App\Actions\Listings\CreateListing;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Returns\UpsertReturn;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ReturnReasonFault;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Imports\NormalizedOrder;
use App\Imports\NormalizedReturn;
use App\Models\Claim;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\OrderReturn;
use App\Models\Shop;
use App\Models\Tenant;
use App\Support\Money;
use App\Tenancy\TenantContext;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('ReturnFeeClaimTenant');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Helpers — mirrors the classifyTestShopWithOrder() pattern from
// ReturnReasonClassificationTest.php
// ---------------------------------------------------------------------------

/**
 * Build a minimal returnable order on a Shopee shop.
 *
 * @return array{0: Shop, 1: OrderLine}
 */
function rfcShopWithOrder(): array
{
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
    $shop = app(CreateShop::class)->handle('ร้าน RFC', Platform::Shopee, $location);
    $product = app(CreateProduct::class)->handle('สินค้า RFC', [
        ['master_sku' => 'RFC-'.uniqid(), 'list_price' => Money::fromBaht('100')],
    ]);
    app(CreateListing::class)->handle($shop, $product);

    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'RFC-ORD-'.uniqid(),
        status: OrderStatus::Completed,
        lines: [['variant' => $product->variants->firstOrFail(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')]],
    ));

    return [$shop, $order->lines->firstOrFail()];
}

/**
 * Upsert a return with the given reason string and return it.
 */
function rfcUpsertReturn(Shop $shop, OrderLine $orderLine, ?string $reason, string $returnId): OrderReturn
{
    return app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: $returnId,
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: $reason,
    ));
}

// ---------------------------------------------------------------------------
// FlagReturnFeeClaim unit-level
// ---------------------------------------------------------------------------

describe('FlagReturnFeeClaim — direct invocation', function () {
    it('creates an eligible return_fee Claim for a seller-fault Return', function () {
        [$shop, $orderLine] = rfcShopWithOrder();

        $return = rfcUpsertReturn($shop, $orderLine, 'ได้รับสินค้าผิด', 'UNIT-SF-1');
        // Claim was created by UpsertReturn; verify via direct invocation on a fresh OrderReturn
        // (idempotency guard returns the existing one — count still 1)
        $claim = app(FlagReturnFeeClaim::class)->handle($return);

        expect($claim)->not->toBeNull()
            ->and($claim?->claim_type)->toBe(ClaimType::ReturnFee)
            ->and($claim?->status)->toBe(ClaimStatus::Eligible)
            ->and($claim?->ref_return_id)->toBe($return->id)
            ->and($claim?->ref_order_id)->toBe($orderLine->order_id);
    });

    it('returns null for a buyer-fault Return', function () {
        [$shop, $orderLine] = rfcShopWithOrder();

        $return = rfcUpsertReturn($shop, $orderLine, 'ฉันต้องการคืนสินค้าในสภาพสมบูรณ์', 'UNIT-BF-1');

        expect($return->reason_fault)->toBe(ReturnReasonFault::BuyerFault);

        $claim = app(FlagReturnFeeClaim::class)->handle($return);
        expect($claim)->toBeNull();
    });

    it('returns null for a Return with null reason_fault', function () {
        [$shop, $orderLine] = rfcShopWithOrder();

        $return = rfcUpsertReturn($shop, $orderLine, null, 'UNIT-NULL-1');

        expect($return->reason_fault)->toBeNull();

        $claim = app(FlagReturnFeeClaim::class)->handle($return);
        expect($claim)->toBeNull();
    });

    it('returns null for a Return with an unrecognised reason (null fault)', function () {
        [$shop, $orderLine] = rfcShopWithOrder();

        $return = rfcUpsertReturn($shop, $orderLine, 'เหตุผลที่ระบบไม่รู้จัก', 'UNIT-UNK-1');

        expect($return->reason_fault)->toBeNull();

        $claim = app(FlagReturnFeeClaim::class)->handle($return);
        expect($claim)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// UpsertReturn integration — auto-flag wired in
// ---------------------------------------------------------------------------

describe('UpsertReturn auto-flag integration', function () {
    it('seller-fault Return import creates exactly one eligible return_fee Claim', function () {
        [$shop, $orderLine] = rfcShopWithOrder();

        $return = rfcUpsertReturn($shop, $orderLine, 'ได้รับสินค้าผิด', 'INT-SF-1');

        $claims = Claim::query()
            ->where('claim_type', ClaimType::ReturnFee)
            ->where('ref_return_id', $return->id)
            ->get();

        expect($claims)->toHaveCount(1);
        $claim = $claims->first();

        expect($claim?->claim_type)->toBe(ClaimType::ReturnFee)
            ->and($claim?->status)->toBe(ClaimStatus::Eligible)
            ->and($claim?->ref_return_id)->toBe($return->id)
            ->and($claim?->ref_order_id)->toBe($orderLine->order_id);
    });

    it('buyer-fault Return import creates no Claim', function () {
        [$shop, $orderLine] = rfcShopWithOrder();

        $return = rfcUpsertReturn($shop, $orderLine, 'ฉันต้องการคืนสินค้าในสภาพสมบูรณ์', 'INT-BF-1');

        expect($return->reason_fault)->toBe(ReturnReasonFault::BuyerFault);

        $count = Claim::query()
            ->where('claim_type', ClaimType::ReturnFee)
            ->where('ref_return_id', $return->id)
            ->count();

        expect($count)->toBe(0);
    });

    it('unrecognised reason (null fault) creates no Claim', function () {
        [$shop, $orderLine] = rfcShopWithOrder();

        $return = rfcUpsertReturn($shop, $orderLine, 'เหตุผลแปลกใหม่', 'INT-UNK-1');

        expect($return->reason_fault)->toBeNull();

        $count = Claim::query()
            ->where('claim_type', ClaimType::ReturnFee)
            ->where('ref_return_id', $return->id)
            ->count();

        expect($count)->toBe(0);
    });

    it('null return_reason creates no Claim', function () {
        [$shop, $orderLine] = rfcShopWithOrder();

        $return = rfcUpsertReturn($shop, $orderLine, null, 'INT-NULL-1');

        $count = Claim::query()
            ->where('claim_type', ClaimType::ReturnFee)
            ->where('ref_return_id', $return->id)
            ->count();

        expect($count)->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Idempotency — re-import must not create a second Claim
// ---------------------------------------------------------------------------

it('re-importing a seller-fault Return creates no duplicate Claim', function () {
    [$shop, $orderLine] = rfcShopWithOrder();

    $normalized = new NormalizedReturn(
        platformReturnId: 'IDEM-1',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: 'ได้รับสินค้าผิด',
    );

    app(UpsertReturn::class)->handle($shop, $normalized);
    app(UpsertReturn::class)->handle($shop, $normalized);
    app(UpsertReturn::class)->handle($shop, $normalized);

    $claimCount = Claim::query()
        ->where('claim_type', ClaimType::ReturnFee)
        ->whereHas('orderReturn', fn ($q) => $q->where('platform_return_id', 'IDEM-1'))
        ->count();

    expect($claimCount)->toBe(1);
});

it('re-importing a buyer-fault Return leaves Claim count at zero', function () {
    [$shop, $orderLine] = rfcShopWithOrder();

    $normalized = new NormalizedReturn(
        platformReturnId: 'IDEM-BF-1',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: 'ฉันต้องการคืนสินค้าในสภาพสมบูรณ์',
    );

    app(UpsertReturn::class)->handle($shop, $normalized);
    app(UpsertReturn::class)->handle($shop, $normalized);

    $claimCount = Claim::query()
        ->where('claim_type', ClaimType::ReturnFee)
        ->whereHas('orderReturn', fn ($q) => $q->where('platform_return_id', 'IDEM-BF-1'))
        ->count();

    expect($claimCount)->toBe(0);
});

// ---------------------------------------------------------------------------
// Claim links back to the Return (so Return Reason + Buyer Note are reachable)
// ---------------------------------------------------------------------------

it('the Claim links to its Return and its Order so Return Reason is reachable', function () {
    [$shop, $orderLine] = rfcShopWithOrder();

    $return = rfcUpsertReturn($shop, $orderLine, 'ได้รับสินค้าผิด', 'LINK-1');
    // add a buyer note for drilldown
    $return->update(['buyer_note' => 'ของไม่ตรงเลยนะ']);

    $claim = Claim::query()
        ->where('claim_type', ClaimType::ReturnFee)
        ->where('ref_return_id', $return->id)
        ->firstOrFail();

    expect($claim->orderReturn()->firstOrFail()->return_reason)->toBe('ได้รับสินค้าผิด')
        ->and($claim->orderReturn()->firstOrFail()->buyer_note)->toBe('ของไม่ตรงเลยนะ')
        ->and($claim->order()->firstOrFail()->id)->toBe($orderLine->order_id);
});

// ---------------------------------------------------------------------------
// Cross-tenant isolation (ADR 0011)
// ---------------------------------------------------------------------------

it('passes the cross-tenant isolation harness for auto-flagged Claims', function () {
    assertTenantIsolation(function (): Claim {
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        $shop = app(CreateShop::class)->handle('ร้าน xten', Platform::Shopee, $location);
        $product = app(CreateProduct::class)->handle('สินค้า xten', [
            ['master_sku' => 'XTEN-RFC-'.uniqid(), 'list_price' => Money::fromBaht('100')],
        ]);
        app(CreateListing::class)->handle($shop, $product);

        $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
            platformOrderId: 'XTEN-RFC-ORD-'.uniqid(),
            status: OrderStatus::Completed,
            lines: [['variant' => $product->variants->firstOrFail(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')]],
        ));

        $orderLine = $order->lines->firstOrFail();

        $return = app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
            platformReturnId: 'XTEN-RFC-'.uniqid(),
            returnType: ReturnType::ReturnAndRefund,
            subStatus: ReturnSubStatus::AwaitingBuyerShipment,
            lines: [['order_line' => $orderLine, 'qty' => 1]],
            returnReason: 'ได้รับสินค้าผิด',
        ));

        return Claim::query()
            ->where('claim_type', ClaimType::ReturnFee)
            ->where('ref_return_id', $return->id)
            ->firstOrFail();
    });
});

it('cross-tenant: Tenant B cannot see Tenant A\'s auto-flagged Claim', function () {
    [$shopA, $orderLineA] = rfcShopWithOrder();

    $returnA = rfcUpsertReturn($shopA, $orderLineA, 'ได้รับสินค้าผิด', 'XTEN-A-1');

    $claimA = Claim::query()
        ->where('claim_type', ClaimType::ReturnFee)
        ->where('ref_return_id', $returnA->id)
        ->firstOrFail();

    // Switch to Tenant B
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('XTenB-RFC');
    app(TenantContext::class)->set($tenantB);

    $locationB = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง B']);
    $shopB = app(CreateShop::class)->handle('ร้าน B', Platform::Shopee, $locationB);
    $productB = app(CreateProduct::class)->handle('สินค้า B', [
        ['master_sku' => 'XTEN-RFC-B-'.uniqid(), 'list_price' => Money::fromBaht('100')],
    ]);
    app(CreateListing::class)->handle($shopB, $productB);
    $orderB = app(ImportMarketplaceOrder::class)->handle($shopB, new NormalizedOrder(
        platformOrderId: 'XTEN-B-ORD-'.uniqid(),
        status: OrderStatus::Completed,
        lines: [['variant' => $productB->variants->firstOrFail(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')]],
    ));
    $orderLineB = $orderB->lines->firstOrFail();

    $returnB = rfcUpsertReturn($shopB, $orderLineB, 'ได้รับสินค้าผิด', 'XTEN-B-1');

    $claimBIds = Claim::query()->where('claim_type', ClaimType::ReturnFee)->pluck('id');

    expect($claimBIds)->not->toContain($claimA->id);
    expect(
        Claim::query()
            ->where('claim_type', ClaimType::ReturnFee)
            ->where('ref_return_id', $returnB->id)
            ->exists()
    )->toBeTrue();

    // Back to Tenant A — Tenant A sees its own Claim, not B's
    app(TenantContext::class)->forget();
    $tenantA = Tenant::query()->withoutGlobalScopes()->where('name', 'ReturnFeeClaimTenant')->firstOrFail();
    app(TenantContext::class)->set($tenantA);

    $claimAIds = Claim::query()->where('claim_type', ClaimType::ReturnFee)->pluck('id');

    expect($claimAIds)->toContain($claimA->id)
        ->and($claimAIds)->not->toContain(
            Claim::query()->withoutGlobalScopes()->where('ref_return_id', $returnB->id)->value('id')
        );
});
