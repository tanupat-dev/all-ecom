<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Imports\StartImport;
use App\Actions\Listings\CreateListing;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Imports\ShopeeOrderImporter;
use App\Imports\UnmappedPlatformStatusException;
use App\Models\Location;
use App\Models\Order;
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

function shopeeShop(): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $shop = app(CreateShop::class)->handle('shopee1', Platform::Shopee, $location);

    $product = app(CreateProduct::class)->handle('เสื้อยืด', [
        ['master_sku' => 'TS-RED-M', 'name' => 'แดง / M', 'list_price' => Money::fromBaht('199')],
        ['master_sku' => 'TS-RED-L', 'name' => 'แดง / L', 'list_price' => Money::fromBaht('199')],
    ]);
    app(CreateListing::class)->handle($shop, $product);

    return $shop;
}

/**
 * The real export's 59 Thai headers (ref doc/shopee/All order shopee.xlsx).
 *
 * @return list<string>
 */
function shopeeOrderHeaders(): array
{
    return [
        'หมายเลขคำสั่งซื้อ', 'สถานะการสั่งซื้อ', 'Hot Listing', 'เหตุผลในการยกเลิกคำสั่งซื้อ',
        'สถานะการคืนเงินหรือคืนสินค้า', 'ชื่อผู้ใช้ (ผู้ซื้อ)', 'วันที่ทำการสั่งซื้อ',
        'เวลาการชำระสินค้า', 'ช่องทางการชำระเงิน', 'ช่องทางการชำระเงิน (รายละเอียด)',
        'แผนการผ่อนชำระ', 'ค่าธรรมเนียม (%)', 'ตัวเลือกการจัดส่ง', 'วิธีการจัดส่ง',
        '*หมายเลขติดตามพัสดุ', 'วันที่คาดว่าจะทำการจัดส่งสินค้า', 'เวลาส่งสินค้า',
        'เลขอ้างอิง Parent SKU', 'ชื่อสินค้า', 'เลขอ้างอิง SKU (SKU Reference No.)',
        'ชื่อตัวเลือก', 'ราคาตั้งต้น', 'ราคาขาย', 'จำนวน', 'จำนวนที่ส่งคืน', 'ราคาขายสุทธิ',
        'ส่วนลดจาก Shopee', 'โค้ดส่วนลดชำระโดยผู้ขาย', 'โค้ด Coins Cashback ชำระโดยผู้ขาย',
        'โค้ดส่วนลดชำระโดย Shopee (เช่น โค้ดจากโปรแกรม ร้านโค้ดคุ้ม, โค้ดส่วนลด Shopee, โค้ดส่วนลด Shopee Mall)',
        'โค้ดส่วนลด', 'เข้าร่วมแคมเปญ bundle deal หรือไม่', 'ส่วนลด bundle deal ชำระโดยผู้ขาย',
        'ส่วนลด bundle deal ชำระโดย Shopee', 'ส่วนลดจากการใช้เหรียญ', 'โปรโมชั่นช่องทางชำระเงินทั้งหมด',
        'ส่วนลดเครื่องเก่าแลกใหม่', 'โบนัสส่วนลดเครื่องเก่าแลกใหม่', 'ค่าคอมมิชชั่น', 'Transaction Fee',
        'ราคาสินค้าที่ชำระโดยผู้ซื้อ (THB)', 'ค่าจัดส่งที่ชำระโดยผู้ซื้อ',
        'ค่าจัดส่งที่ Shopee ออกให้โดยประมาณ', 'ค่าจัดส่งสินค้าคืน', 'ค่าบริการ', 'จำนวนเงินทั้งหมด',
        'ค่าจัดส่งโดยประมาณ', 'โบนัสส่วนลดเครื่องเก่าแลกใหม่จากผู้ขาย', 'ชื่อผู้รับ', 'หมายเลขโทรศัพท์',
        'หมายเหตุจากผู้ซื้อ', 'ที่อยู่ในการจัดส่ง', 'ประเทศ', 'จังหวัด', 'เขต/อำเภอ', 'รหัสไปรษณีย์',
        'ประเภทคำสั่งซื้อ', 'เวลาที่ทำการสั่งซื้อสำเร็จ', 'บันทึก',
    ];
}

/**
 * Synthetic rows under the real headers — no real buyer data committed.
 *
 * @param  list<array<string, string>>  $rows  header => value, unset = ''
 */
function writeShopeeXlsx(array $rows): string
{
    $headers = shopeeOrderHeaders();
    $path = sys_get_temp_dir().'/shopee-import-test-'.uniqid().'.xlsx';
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

/**
 * The same export as CSV — platforms fall back to CSV when their Excel
 * export breaks; every importer must take both.
 *
 * @param  list<array<string, string>>  $rows
 */
function writeShopeeCsv(array $rows): string
{
    $headers = shopeeOrderHeaders();
    $path = sys_get_temp_dir().'/shopee-import-test-'.uniqid().'.csv';
    $handle = fopen($path, 'w') ?: throw new RuntimeException("Cannot open [{$path}]");
    fputcsv($handle, $headers);

    foreach ($rows as $row) {
        fputcsv($handle, array_map(
            static fn (string $header): string => $row[$header] ?? '',
            $headers,
        ));
    }

    fclose($handle);

    return $path;
}

it('maps every observed Shopee native status, including the variable received-until family', function () {
    $mapper = app(ShopeeOrderImporter::class);

    expect($mapper->map('ที่ต้องจัดส่ง'))->toBe(OrderStatus::AwaitingPack)
        ->and($mapper->map('การจัดส่ง'))->toBe(OrderStatus::InTransit)
        ->and($mapper->map('จัดส่งสำเร็จแล้ว'))->toBe(OrderStatus::Delivered)
        ->and($mapper->map('ผู้ซื้อได้รับสินค้าแล้ว โปรดทราบว่าผู้ซื้อสามารถยื่นคำขอคืนเงิน/คืนสินค้าได้จนถึง 2026-06-07'))->toBe(OrderStatus::Delivered)
        ->and($mapper->map('สำเร็จแล้ว'))->toBe(OrderStatus::Completed)
        ->and($mapper->map('ยกเลิกแล้ว'))->toBe(OrderStatus::Cancelled);
});

it('fails loud on a Shopee status it has never seen', function () {
    app(ShopeeOrderImporter::class)->map('สถานะใหม่จาก Shopee');
})->throws(UnmappedPlatformStatusException::class, 'ระบบไม่รองรับ');

it('imports a Shopee order export end-to-end: orders, aggregated lines, milestones in UTC, tracking', function () {
    $shop = shopeeShop();

    $file = new UploadedFile(writeShopeeXlsx([
        [
            'หมายเลขคำสั่งซื้อ' => '2606CXKD11AB', 'สถานะการสั่งซื้อ' => 'สำเร็จแล้ว',
            'ชื่อผู้ใช้ (ผู้ซื้อ)' => 'buyer_one',
            'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01', 'เวลาการชำระสินค้า' => '2026-05-06 00:05',
            'เวลาส่งสินค้า' => '2026-05-07 09:30', 'เวลาที่ทำการสั่งซื้อสำเร็จ' => '2026-05-11 13:50',
            '*หมายเลขติดตามพัสดุ' => 'TH99001',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-M', 'ราคาขาย' => '159', 'จำนวน' => '2',
        ],
        [
            'หมายเลขคำสั่งซื้อ' => '2606CXKD11AB', 'สถานะการสั่งซื้อ' => 'สำเร็จแล้ว',
            'ชื่อผู้ใช้ (ผู้ซื้อ)' => 'buyer_one',
            'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01', 'เวลาการชำระสินค้า' => '2026-05-06 00:05',
            'เวลาส่งสินค้า' => '2026-05-07 09:30', 'เวลาที่ทำการสั่งซื้อสำเร็จ' => '2026-05-11 13:50',
            '*หมายเลขติดตามพัสดุ' => 'TH99001',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-L', 'ราคาขาย' => '199.50', 'จำนวน' => '1',
        ],
        [
            'หมายเลขคำสั่งซื้อ' => '2606ZZAA22CD', 'สถานะการสั่งซื้อ' => 'ที่ต้องจัดส่ง',
            'ชื่อผู้ใช้ (ผู้ซื้อ)' => 'buyer_two',
            'วันที่ทำการสั่งซื้อ' => '2026-06-10 18:00', 'เวลาการชำระสินค้า' => '2026-06-10 18:02',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-M', 'ราคาขาย' => '159', 'จำนวน' => '1',
        ],
    ]), 'Order.completed.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeOrderImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    $completed = Order::query()->where('platform_order_id', '2606CXKD11AB')->firstOrFail();
    $pending = Order::query()->where('platform_order_id', '2606ZZAA22CD')->firstOrFail();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($completed->status)->toBe(OrderStatus::Completed)
        ->and($completed->lines)->toHaveCount(2)
        ->and($completed->total?->satang)->toBe(51750)
        ->and($completed->tracking_number)->toBe('TH99001')
        ->and($completed->buyer_name)->toBe('buyer_one')
        // Thai wall-clock 2026-05-11 13:50 stored as UTC (ADR: store UTC).
        ->and($completed->completed_date?->format('Y-m-d H:i'))->toBe('2026-05-11 06:50')
        ->and($completed->delivered_date)->toBeNull()
        ->and($pending->status)->toBe(OrderStatus::AwaitingPack)
        ->and($pending->lines->first()?->qty)->toBe(1);
});

it('holds a row whose Platform SKU has no mapping while the rest of the file lands', function () {
    $shop = shopeeShop();

    $file = new UploadedFile(writeShopeeXlsx([
        [
            'หมายเลขคำสั่งซื้อ' => '2606OK', 'สถานะการสั่งซื้อ' => 'สำเร็จแล้ว',
            'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-M', 'ราคาขาย' => '159', 'จำนวน' => '1',
        ],
        [
            'หมายเลขคำสั่งซื้อ' => '2606BAD', 'สถานะการสั่งซื้อ' => 'สำเร็จแล้ว',
            'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'NOT-MAPPED', 'ราคาขาย' => '99', 'จำนวน' => '1',
        ],
    ]), 'orders.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeOrderImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('NOT-MAPPED')
        ->and(Order::query()->where('platform_order_id', '2606OK')->exists())->toBeTrue()
        ->and(Order::query()->where('platform_order_id', '2606BAD')->exists())->toBeFalse();
});

it('imports the same Shopee export delivered as CSV — when the platform cannot serve Excel', function () {
    $shop = shopeeShop();

    $file = new UploadedFile(writeShopeeCsv([
        [
            'หมายเลขคำสั่งซื้อ' => '2606CSV1', 'สถานะการสั่งซื้อ' => 'สำเร็จแล้ว',
            'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01',
            'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-M', 'ราคาขาย' => '159', 'จำนวน' => '2',
        ],
    ]), 'orders.csv', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeOrderImporter::class, ['shop_id' => $shop->id]);

    $order = Order::query()->where('platform_order_id', '2606CSV1')->firstOrFail();

    expect($job->refresh()->status)->toBe(ImportJobStatus::Completed)
        ->and($order->status)->toBe(OrderStatus::Completed)
        ->and($order->total?->satang)->toBe(31800);
});

it('gates the order import on order.import via the Shop policy', function () {
    [$tenant, $admin] = tenantWithUser('Admin');
    $shop = shopeeShop();

    expect($admin->can('importOrders', $shop))->toBeTrue();

    $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
    $cashier->assignRole('Cashier');

    expect($cashier->can('importOrders', $shop))->toBeFalse();
});

it('re-importing the same Shopee file is idempotent', function () {
    $shop = shopeeShop();
    $rows = [[
        'หมายเลขคำสั่งซื้อ' => '2606CXKD11AB', 'สถานะการสั่งซื้อ' => 'สำเร็จแล้ว',
        'วันที่ทำการสั่งซื้อ' => '2026-05-06 00:01',
        'เลขอ้างอิง SKU (SKU Reference No.)' => 'TS-RED-M', 'ราคาขาย' => '159', 'จำนวน' => '2',
    ]];

    foreach ([1, 2] as $run) {
        $file = new UploadedFile(writeShopeeXlsx($rows), 'orders.xlsx', null, null, true);
        app(StartImport::class)->handle($file, ShopeeOrderImporter::class, ['shop_id' => $shop->id]);
    }

    expect(Order::query()->count())->toBe(1)
        ->and(Order::query()->firstOrFail()->lines)->toHaveCount(1);
});
