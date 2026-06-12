<?php

use App\Actions\Imports\StartImport;
use App\Actions\Tenants\CreateTenant;
use App\Enums\CancelledBy;
use App\Enums\CancelReasonCategory;
use App\Enums\ImportJobStatus;
use App\Imports\LazadaOrderImporter;
use App\Imports\ShopeeOrderImporter;
use App\Imports\TiktokOrderImporter;
use App\Models\Order;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
    Storage::fake('local');
    actingAs(User::factory()->create());
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Fixture writers + shop builders come from the per-platform import tests. */
it('parses the Shopee bundled cancel string — actor, category, and raw source', function () {
    $shop = shopeeShop();

    $file = new UploadedFile(writeShopeeXlsx([
        [
            'หมายเลขคำสั่งซื้อ' => 'SP-C1', 'สถานะการสั่งซื้อ' => 'ยกเลิกแล้ว',
            'เหตุผลในการยกเลิกคำสั่งซื้อ' => 'ยกเลิกโดยผู้ขาย เหตุผล : สินค้าหมด',
            'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-M', 'ราคาขาย' => '159', 'จำนวน' => '1',
        ],
        [
            // The system-cancel variant carries a stray <br>.
            'หมายเลขคำสั่งซื้อ' => 'SP-C2', 'สถานะการสั่งซื้อ' => 'ยกเลิกแล้ว',
            'เหตุผลในการยกเลิกคำสั่งซื้อ' => 'ยกเลิกโดยอัตโนมัติจากระบบของ Shopee <br>เหตุผล : ไม่มีการชำระเงิน',
            'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-M', 'ราคาขาย' => '159', 'จำนวน' => '1',
        ],
    ]), 'orders.xlsx', null, null, true);

    app(StartImport::class)->handle($file, ShopeeOrderImporter::class, ['shop_id' => $shop->id]);

    $sellerCancel = Order::query()->where('platform_order_id', 'SP-C1')->firstOrFail();
    $systemCancel = Order::query()->where('platform_order_id', 'SP-C2')->firstOrFail();

    expect($sellerCancel->cancelled_by)->toBe(CancelledBy::Seller)
        ->and($sellerCancel->cancel_reason_category)->toBe(CancelReasonCategory::OutOfStock)
        ->and($sellerCancel->cancel_reason_source)->toBe('สินค้าหมด')
        ->and($systemCancel->cancelled_by)->toBe(CancelledBy::System)
        ->and($systemCancel->cancel_reason_category)->toBe(CancelReasonCategory::PaymentIssue);
});

it('holds a Shopee row whose cancel reason has no explicit category mapping', function () {
    $shop = shopeeShop();

    $file = new UploadedFile(writeShopeeXlsx([
        [
            'หมายเลขคำสั่งซื้อ' => 'SP-C3', 'สถานะการสั่งซื้อ' => 'ยกเลิกแล้ว',
            'เหตุผลในการยกเลิกคำสั่งซื้อ' => 'ยกเลิกโดยผู้ซื้อ เหตุผล : เหตุผลใหม่ที่ไม่เคยเห็น',
            'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-M', 'ราคาขาย' => '159', 'จำนวน' => '1',
        ],
    ]), 'orders.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeOrderImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('เหตุผลใหม่ที่ไม่เคยเห็น')
        ->and(Order::query()->where('platform_order_id', 'SP-C3')->exists())->toBeFalse();
});

it('reads the TikTok Cancel By / Cancel Reason columns', function () {
    $shop = tiktokShop();

    $file = new UploadedFile(writeTiktokCsv([
        [
            'Order ID' => 'TT-C1', 'Order Status' => 'ยกเลิกแล้ว', 'Order Substatus' => 'ยกเลิกแล้ว',
            'Cancel By' => 'User', 'Cancel Reason' => 'ไม่ต้องการแล้ว',
            'Seller SKU' => 'TS-RED-M', 'Quantity' => '1',
            'SKU Subtotal Before Discount' => '199', 'SKU Seller Discount' => '0',
            'Created Time' => "05/06/2026 09:00:00\t",
        ],
    ]), 'All order tiktok.csv', null, null, true);

    app(StartImport::class)->handle($file, TiktokOrderImporter::class, ['shop_id' => $shop->id]);

    $order = Order::query()->where('platform_order_id', 'TT-C1')->firstOrFail();

    expect($order->cancelled_by)->toBe(CancelledBy::Buyer)
        ->and($order->cancel_reason_category)->toBe(CancelReasonCategory::BuyerChangedMind)
        ->and($order->cancel_reason_source)->toBe('ไม่ต้องการแล้ว');
});

it('leaves the cancellation fields null for Lazada — its export exposes no reason', function () {
    $shop = lazadaShop();

    $file = new UploadedFile(writeLazadaXlsx([
        ['orderNumber' => 'LZ-C1', 'sellerSku' => 'TS-RED-M', 'status' => 'canceled', 'createTime' => '03 Jun 2026 20:41', 'unitPrice' => '199.00'],
    ]), 'orders.xlsx', null, null, true);

    app(StartImport::class)->handle($file, LazadaOrderImporter::class, ['shop_id' => $shop->id]);

    $order = Order::query()->where('platform_order_id', 'LZ-C1')->firstOrFail();

    expect($order->cancelled_by)->toBeNull()
        ->and($order->cancel_reason_category)->toBeNull()
        ->and($order->cancel_reason_source)->toBeNull();
});

it('never writes cancellation fields onto a non-cancelled order', function () {
    $shop = shopeeShop();

    $file = new UploadedFile(writeShopeeXlsx([
        [
            'หมายเลขคำสั่งซื้อ' => 'SP-C4', 'สถานะการสั่งซื้อ' => 'สำเร็จแล้ว',
            // A stale reason cell on a live order must be ignored.
            'เหตุผลในการยกเลิกคำสั่งซื้อ' => 'ยกเลิกโดยผู้ขาย เหตุผล : สินค้าหมด',
            'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-M', 'ราคาขาย' => '159', 'จำนวน' => '1',
        ],
    ]), 'orders.xlsx', null, null, true);

    app(StartImport::class)->handle($file, ShopeeOrderImporter::class, ['shop_id' => $shop->id]);

    expect(Order::query()->where('platform_order_id', 'SP-C4')->firstOrFail()->cancelled_by)->toBeNull();
});
