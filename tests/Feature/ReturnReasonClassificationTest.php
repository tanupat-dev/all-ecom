<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\CreateListing;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Returns\ClassifyReturnReason;
use App\Actions\Returns\UpsertReturn;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ReturnReasonFault;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Filament\Resources\UnclassifiedReturns\UnclassifiedReturnResource;
use App\Imports\NormalizedOrder;
use App\Imports\NormalizedReturn;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\OrderReturn;
use App\Models\Shop;
use App\Models\Tenant;
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

// ---------------------------------------------------------------------------
// ClassifyReturnReason unit-level tests
// ---------------------------------------------------------------------------

describe('ClassifyReturnReason — Shopee', function () {
    it('maps the single buyer-fault reason to buyer_fault', function () {
        $action = app(ClassifyReturnReason::class);
        expect($action->handle(Platform::Shopee, 'ฉันต้องการคืนสินค้าในสภาพสมบูรณ์'))
            ->toBe(ReturnReasonFault::BuyerFault);
    });

    it('maps listed seller-fault codes to seller_fault', function () {
        $action = app(ClassifyReturnReason::class);

        foreach ([
            'สินค้าหมดอายุ',
            'ทำงานไม่สมบูรณ์',
            'แตกหัก',
            'รอยขีดข่วน/บุบ',
            'ความเสียหายอื่นๆ',
            'ได้รับสินค้าผิด',
            'สินค้าแตกต่างจากที่สั่ง',
            'ไม่ได้รับพัสดุ',
            'สินค้าไม่ครบ/ชิ้นส่วนไม่ครบ',
            'กล่องเปล่า',
        ] as $reason) {
            expect($action->handle(Platform::Shopee, $reason))
                ->toBe(ReturnReasonFault::SellerFault, "Shopee reason [{$reason}] should be seller_fault");
        }
    });

    it('returns null for an unknown Shopee reason (fail-loud ADR 0005)', function () {
        $action = app(ClassifyReturnReason::class);
        expect($action->handle(Platform::Shopee, 'เหตุผลใหม่ที่ไม่รู้จัก'))->toBeNull();
    });

    it('returns null for a null reason string', function () {
        $action = app(ClassifyReturnReason::class);
        expect($action->handle(Platform::Shopee, null))->toBeNull();
    });
});

describe('ClassifyReturnReason — TikTok', function () {
    it('maps the buyer-fault reasons to buyer_fault (Thai and English)', function () {
        $action = app(ClassifyReturnReason::class);

        expect($action->handle(Platform::Tiktok, 'ไม่ต้องการอีกต่อไป'))->toBe(ReturnReasonFault::BuyerFault);
        expect($action->handle(Platform::Tiktok, 'No longer needed'))->toBe(ReturnReasonFault::BuyerFault);
    });

    it('maps listed seller-fault texts to seller_fault', function () {
        $action = app(ClassifyReturnReason::class);

        foreach ([
            'สินค้าไม่ตรงกับคำอธิบาย',
            'สินค้าไม่ถูกต้อง/ส่งสินค้าผิด',
            'สินค้ามีตำหนิหรือใช้งานไม่ได้',
            'ได้รับพัสดุแต่มีสินค้าขาดหาย',
            'พัสดุหรือสินค้าเสียหาย',
            'ไม่ได้รับพัสดุ',
            'สงสัยว่าเป็นของปลอม',
            'สินค้าหมดอายุ',
            'บรรจุภัณฑ์เสียหาย',
        ] as $reason) {
            expect($action->handle(Platform::Tiktok, $reason))
                ->toBe(ReturnReasonFault::SellerFault, "TikTok reason [{$reason}] should be seller_fault");
        }
    });

    it('returns null for an unknown TikTok reason (fail-loud)', function () {
        $action = app(ClassifyReturnReason::class);
        expect($action->handle(Platform::Tiktok, 'เหตุผลไม่รู้จัก'))->toBeNull();
    });

    it('signals pre-shipment cancellation reasons as skip (not returns)', function () {
        $action = app(ClassifyReturnReason::class);

        foreach ([
            'สินค้ามาถึงไม่ตรงเวลา',
            'ต้องการเปลี่ยนวิธีการชำระเงิน',
            'จำเป็นต้องเปลี่ยนที่อยู่จัดส่ง',
            'มีราคาที่ดีกว่า',
        ] as $reason) {
            expect($action->handle(Platform::Tiktok, $reason))
                ->toBeNull("TikTok pre-shipment [{$reason}] should return null (skip)");
            expect($action->isPreShipmentCancellation(Platform::Tiktok, $reason))
                ->toBeTrue("TikTok pre-shipment [{$reason}] should be flagged as skip");
        }
    });

    it('does not flag unknown reasons as pre-shipment skips', function () {
        $action = app(ClassifyReturnReason::class);
        $unknown = 'เหตุผลไม่รู้จัก';

        expect($action->handle(Platform::Tiktok, $unknown))->toBeNull()
            ->and($action->isPreShipmentCancellation(Platform::Tiktok, $unknown))->toBeFalse();
    });

    it('only TikTok can have pre-shipment cancellations — other platforms never match', function () {
        $action = app(ClassifyReturnReason::class);
        // 'สินค้ามาถึงไม่ตรงเวลา' is TikTok-only; Shopee/Lazada must not treat it as skip
        expect($action->isPreShipmentCancellation(Platform::Shopee, 'สินค้ามาถึงไม่ตรงเวลา'))->toBeFalse()
            ->and($action->isPreShipmentCancellation(Platform::Lazada, 'สินค้ามาถึงไม่ตรงเวลา'))->toBeFalse();
    });
});

describe('ClassifyReturnReason — Lazada', function () {
    it('maps Change of mind to buyer_fault', function () {
        $action = app(ClassifyReturnReason::class);
        expect($action->handle(Platform::Lazada, 'Change of mind'))->toBe(ReturnReasonFault::BuyerFault);
    });

    it('maps listed seller-fault English texts to seller_fault', function () {
        $action = app(ClassifyReturnReason::class);

        foreach ([
            'Expired items',
            'Missing items in the parcel delivered',
            'Item physically damaged upon opening parcel',
            'Outer Packaging of the item is damage',
            'Item size is not advertised',
            'Missing accessories or freebies',
            'Counterfeit items',
            'Item is defective or not working as intended',
            'Wrong items delivered',
            'Item/ quality doesn\'t match description or pictures',
        ] as $reason) {
            expect($action->handle(Platform::Lazada, $reason))
                ->toBe(ReturnReasonFault::SellerFault, "Lazada reason [{$reason}] should be seller_fault");
        }
    });

    it('returns null for an unknown Lazada reason (fail-loud)', function () {
        $action = app(ClassifyReturnReason::class);
        expect($action->handle(Platform::Lazada, 'Unknown new Lazada reason'))->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// UpsertReturn integration — reason_fault persisted on the Return
// ---------------------------------------------------------------------------

/**
 * Helper to build a minimal returnable order on a Shopee shop.
 *
 * @return array{0: Shop, 1: OrderLine}
 */
function classifyTestShopWithOrder(): array
{
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
    $shop = app(CreateShop::class)->handle('ร้าน classify', Platform::Shopee, $location);
    $product = app(CreateProduct::class)->handle('สินค้า', [
        ['master_sku' => 'CLS-1', 'list_price' => Money::fromBaht('100')],
    ]);
    app(CreateListing::class)->handle($shop, $product);

    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'CLS-ORD-1',
        status: OrderStatus::Completed,
        lines: [['variant' => $product->variants->firstOrFail(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')]],
    ));

    return [$shop, $order->lines->firstOrFail()];
}

it('UpsertReturn sets reason_fault = buyer_fault for a known Shopee buyer-fault reason', function () {
    [$shop, $orderLine] = classifyTestShopWithOrder();

    $return = app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: 'CLASSIFY-1',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: 'ฉันต้องการคืนสินค้าในสภาพสมบูรณ์',
    ));

    expect($return->reason_fault)->toBe(ReturnReasonFault::BuyerFault);
});

it('UpsertReturn sets reason_fault = seller_fault for a known Shopee seller-fault reason', function () {
    [$shop, $orderLine] = classifyTestShopWithOrder();

    $return = app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: 'CLASSIFY-2',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: 'ได้รับสินค้าผิด',
    ));

    expect($return->reason_fault)->toBe(ReturnReasonFault::SellerFault);
});

it('UpsertReturn stores null for an unknown return reason (fail-loud)', function () {
    [$shop, $orderLine] = classifyTestShopWithOrder();

    $return = app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: 'CLASSIFY-3',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: 'เหตุผลแปลกใหม่ไม่รู้จัก',
    ));

    expect($return->reason_fault)->toBeNull();
});

it('UpsertReturn stores null when return_reason is null', function () {
    [$shop, $orderLine] = classifyTestShopWithOrder();

    $return = app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: 'CLASSIFY-4',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: null,
    ));

    expect($return->reason_fault)->toBeNull();
});

it('reason_fault is idempotent — re-importing the same Return does not change the bucket', function () {
    [$shop, $orderLine] = classifyTestShopWithOrder();

    $normalized = new NormalizedReturn(
        platformReturnId: 'CLASSIFY-5',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: 'ได้รับสินค้าผิด',
    );

    $first = app(UpsertReturn::class)->handle($shop, $normalized);
    $second = app(UpsertReturn::class)->handle($shop, $normalized);

    expect(OrderReturn::query()->where('platform_return_id', 'CLASSIFY-5')->count())->toBe(1)
        ->and($first->reason_fault)->toBe(ReturnReasonFault::SellerFault)
        ->and($second->reason_fault)->toBe(ReturnReasonFault::SellerFault);
});

// ---------------------------------------------------------------------------
// Unclassified list — returns with return_reason but null reason_fault
// ---------------------------------------------------------------------------

it('UnclassifiedReturnResource shows returns with reason but no fault classification', function () {
    [$shop, $orderLine] = classifyTestShopWithOrder();

    // seller-fault reason → classified → NOT in the unclassified list
    app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: 'UNCLASS-KNOWN',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: 'ได้รับสินค้าผิด',
    ));

    // unknown reason → null → SHOULD appear in the unclassified list
    $unknown = app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: 'UNCLASS-UNKNOWN',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: 'เหตุผลไม่รู้จัก',
    ));

    // no reason at all → null reason_fault, but no reason either → NOT in unclassified
    app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: 'UNCLASS-NO-REASON',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: null,
    ));

    $ids = UnclassifiedReturnResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($unknown->id)
        ->and($ids)->not->toContain(
            OrderReturn::query()->where('platform_return_id', 'UNCLASS-KNOWN')->value('id')
        )
        ->and($ids)->not->toContain(
            OrderReturn::query()->where('platform_return_id', 'UNCLASS-NO-REASON')->value('id')
        );
});

it('UnclassifiedReturnResource cross-tenant isolation — each tenant sees only its own unclassified returns', function () {
    // Tenant A (already set in beforeEach) — create an unclassified return
    [$shopA, $orderLineA] = classifyTestShopWithOrder();

    $returnA = app(UpsertReturn::class)->handle($shopA, new NormalizedReturn(
        platformReturnId: 'XTEN-A',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLineA, 'qty' => 1]],
        returnReason: 'เหตุผลไม่รู้จัก A',
    ));

    // Switch to Tenant B
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);

    $locationB = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง B']);
    $shopB = app(CreateShop::class)->handle('ร้าน B', Platform::Shopee, $locationB);
    $productB = app(CreateProduct::class)->handle('สินค้า B', [
        ['master_sku' => 'XTEN-B-1', 'list_price' => Money::fromBaht('100')],
    ]);
    app(CreateListing::class)->handle($shopB, $productB);
    $orderB = app(ImportMarketplaceOrder::class)->handle($shopB, new NormalizedOrder(
        platformOrderId: 'XTEN-B-ORD',
        status: OrderStatus::Completed,
        lines: [['variant' => $productB->variants->firstOrFail(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')]],
    ));
    $orderLineB = $orderB->lines->firstOrFail();

    $returnB = app(UpsertReturn::class)->handle($shopB, new NormalizedReturn(
        platformReturnId: 'XTEN-B',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::AwaitingBuyerShipment,
        lines: [['order_line' => $orderLineB, 'qty' => 1]],
        returnReason: 'เหตุผลไม่รู้จัก B',
    ));

    // In Tenant B context — only B's return is visible
    $idsB = UnclassifiedReturnResource::getEloquentQuery()->pluck('id');
    expect($idsB)->toContain($returnB->id)
        ->and($idsB)->not->toContain($returnA->id);

    // Back to Tenant A — only A's return is visible
    app(TenantContext::class)->forget();
    $tenantA = Tenant::query()->withoutGlobalScopes()->where('name', 'A')->firstOrFail();
    app(TenantContext::class)->set($tenantA);

    $idsA = UnclassifiedReturnResource::getEloquentQuery()->pluck('id');
    expect($idsA)->toContain($returnA->id)
        ->and($idsA)->not->toContain($returnB->id);
});
