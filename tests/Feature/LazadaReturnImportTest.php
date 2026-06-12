<?php

use App\Actions\Imports\StartImport;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Returns\DeriveRefundStatus;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Imports\LazadaReturnImporter;
use App\Imports\NormalizedOrder;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Shop;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

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

/**
 * lazadaShop() comes from LazadaOrderImportTest.
 *
 * @return array{Shop, Order}
 */
function lazadaShopWithOrder(): array
{
    $shop = lazadaShop();
    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'LZ-9001',
        status: OrderStatus::Completed,
        lines: [['variant' => variantBySku('TS-RED-M'), 'qty' => 2, 'unit_price' => Money::fromBaht('267')]],
    ));

    return [$shop, $order];
}

/**
 * The real export's 19 headers, one row per Return Item.
 *
 * @param  list<array<string, string>>  $rows
 */
function writeLazadaReturnXlsx(array $rows): string
{
    $headers = [
        'Order ID', 'Return Order ID', 'buyerName', 'buyerEmail', 'buyerPhone', 'Order Date',
        'Return Order Date', 'Return Item ID', 'Order Item ID', 'Seller SKU ID', 'Item Name',
        'Paid Price + Shipping Fee', 'Refund Amount', 'Return Reason', 'Logistic Status', 'Status',
        'SLA', 'Tracking Number',
    ];

    $path = sys_get_temp_dir().'/lazada-return-test-'.uniqid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($headers));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues(array_map(
            static fn (string $header): string => $row[$header] ?? '',
            $headers,
        )));
    }

    $writer->close();

    return $path;
}

it('imports a Lazada return: per-item rows aggregate, goods journey from Logistic Status, refunded without a timestamp', function () {
    [$shop, $order] = lazadaShopWithOrder();
    $unit = [
        'Return Order ID' => 'LZR-1', 'Order ID' => 'LZ-9001', 'Seller SKU ID' => 'TS-RED-M',
        'Return Order Date' => '2026-06-03 16:35:39', 'Refund Amount' => '267.0',
        'Return Reason' => 'ได้รับสินค้าไม่ตรงตามที่สั่ง',
        'Logistic Status' => 'จัดส่งคืนสินค้าถึงร้านค้าสำเร็จ', 'Status' => 'ReturnClosed',
        'Tracking Number' => 'LRT555',
    ];

    $file = new UploadedFile(writeLazadaReturnXlsx([$unit, $unit]), 'returns.xlsx', null, null, true);
    app(StartImport::class)->handle($file, LazadaReturnImporter::class, ['shop_id' => $shop->id]);

    $return = OrderReturn::query()->where('platform_return_id', 'LZR-1')->firstOrFail();

    expect($return->return_type)->toBe(ReturnType::ReturnAndRefund)
        // ReturnClosed with the goods delivered back is NOT a cancellation
        // — the journey column rules; only Inbound Scan sets รับของกลับแล้ว.
        ->and($return->sub_status)->toBe(ReturnSubStatus::CourierClaimsDelivered)
        ->and($return->lines->first()?->qty)->toBe(2)
        ->and($return->refund_amount?->satang)->toBe(26700)
        ->and($return->refunded)->toBeTrue()
        ->and($return->refunded_at)->toBeNull()
        ->and($return->requested_at?->format('Y-m-d H:i'))->toBe('2026-06-03 09:35')
        ->and(app(DeriveRefundStatus::class)->handle($order))->toBe(RefundStatus::Full);
});

it('treats ReturnClosed with no goods journey as the Platform closure ยกเลิกการคืน', function () {
    [$shop] = lazadaShopWithOrder();

    $file = new UploadedFile(writeLazadaReturnXlsx([[
        'Return Order ID' => 'LZR-2', 'Order ID' => 'LZ-9001', 'Seller SKU ID' => 'TS-RED-M',
        'Return Order Date' => '2026-06-03 16:35:39', 'Refund Amount' => '267.0',
        'Return Reason' => 'เปลี่ยนใจ', 'Logistic Status' => '', 'Status' => 'ReturnClosed',
    ]]), 'returns.xlsx', null, null, true);
    app(StartImport::class)->handle($file, LazadaReturnImporter::class, ['shop_id' => $shop->id]);

    expect(OrderReturn::query()->where('platform_return_id', 'LZR-2')->firstOrFail()->sub_status)
        ->toBe(ReturnSubStatus::Closed);
});

it('treats Refunded with no goods journey as refund_only — money moved, no stock ever will', function () {
    [$shop] = lazadaShopWithOrder();

    $file = new UploadedFile(writeLazadaReturnXlsx([[
        'Return Order ID' => 'LZR-3', 'Order ID' => 'LZ-9001', 'Seller SKU ID' => 'TS-RED-M',
        'Return Order Date' => '2026-06-03 16:35:39', 'Refund Amount' => '267.0',
        'Return Reason' => 'สินค้าเสียหาย', 'Logistic Status' => '', 'Status' => 'Refunded',
    ]]), 'returns.xlsx', null, null, true);
    app(StartImport::class)->handle($file, LazadaReturnImporter::class, ['shop_id' => $shop->id]);

    $return = OrderReturn::query()->where('platform_return_id', 'LZR-3')->firstOrFail();

    expect($return->return_type)->toBe(ReturnType::RefundOnly)
        ->and($return->refunded)->toBeTrue();
});

it('holds a row whose Status or Logistic Status is unknown', function () {
    [$shop] = lazadaShopWithOrder();

    $file = new UploadedFile(writeLazadaReturnXlsx([[
        'Return Order ID' => 'LZR-4', 'Order ID' => 'LZ-9001', 'Seller SKU ID' => 'TS-RED-M',
        'Return Order Date' => '2026-06-03 16:35:39',
        'Return Reason' => 'เปลี่ยนใจ', 'Logistic Status' => 'สถานะขนส่งใหม่', 'Status' => 'Refunded',
    ]]), 'returns.xlsx', null, null, true);
    $job = app(StartImport::class)->handle($file, LazadaReturnImporter::class, ['shop_id' => $shop->id]);

    expect($job->refresh()->error_rows)->toBe(1)
        ->and(OrderReturn::query()->count())->toBe(0);
});
