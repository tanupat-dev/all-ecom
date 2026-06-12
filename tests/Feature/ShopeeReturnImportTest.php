<?php

use App\Actions\Imports\StartImport;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\OrderStatus;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Imports\NormalizedOrder;
use App\Imports\ShopeeReturnImporter;
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

/** shopeeShop() comes from ShopeeOrderImportTest. */
function shopWithReturnableOrder(): Shop
{
    $shop = shopeeShop();
    app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: '2606CXKD11AB',
        status: OrderStatus::Completed,
        lines: [['variant' => variantBySku('TS-RED-M'), 'qty' => 2, 'unit_price' => Money::fromBaht('175')]],
    ));

    return $shop;
}

/**
 * The real return export's key headers (48 columns incl. duplicate
 * address-block names; only the ones the importer reads are filled).
 *
 * @param  list<array<string, string>>  $rows
 */
function writeShopeeReturnXlsx(array $rows): string
{
    $headers = [
        'หมายเลขคำขอคืนเงิน/คืนสินค้า', 'หมายเลขคำสั่งซื้อ', 'วันที่สร้างคำสั่งซื้อ', 'ชื่อผู้ใช้ (ผู้ซื้อ)',
        'ชื่อสินค้า', 'Parent SKU', 'ชื่อตัวเลือกสินค้า', 'เลข SKU', 'ราคาต่อชิ้น',
        'เวลายื่นคำขอคืนเงิน/คืนสินค้า', 'สถานะการคืนเงินหรือคืนสินค้า', 'ประเภทการคืนสินค้า',
        'จำนวนสินค้าคืน', 'ข้อเสนอการคืนเงิน/คืนสินค้า', 'เหตุผลในการขอคืนสินค้า', 'หมายเหตุในการคืนสินค้า',
        'จำนวนเงินคืนทั้งหมด', 'เวลาที่คืนเงิน', 'ส่งสินค้าคืนไปยังคลังสินค้าของ Shopee', 'ช่องทางการส่งสินค้าคืน',
        'หมายเลขติดตามพัสดุสำหรับส่งคืน', 'สถานะการส่งสินค้าคืน', 'เวลาที่จัดส่งสินค้าคืนสำเร็จ',
        'ที่อยู่ในการจัดส่ง', 'จังหวัด', 'เขต/อำเภอ', 'รหัสไปรษณีย์', 'หมายเลขโทรศัพท์',
        'ที่อยู่ในการเข้ารับสินค้า', 'จังหวัด', 'อำเภอ/เขต', 'รหัสไปรษณีย์', 'Buyer contact number',
        'ผู้ขายสามารถยื่นข้อพิพาทได้ภายใน', 'เหตุุผลในการยื่นข้อพิพาท', 'หมายเหตุจากผู้ขาย',
        'เงินชดเชยให้ผู้ขาย (จากการชนะข้อพิพาท/ปรับค่า Seller Balance)', 'ช่องทางการจัดส่งจากผู้ขาย',
        'หมายเลขติดตามพัสดุจากผู้ขาย', 'จำนวนเงินทั้งหมด', 'ช่องทางการชำระเงิน', 'หมายเหตุจากผู้ซื้อ',
        'Hot Listing', 'สถานะการเคลมค่าจัดส่ง', 'รายรับจากคำสั่งซื้อ',
        'ค่าจัดส่งตามจริง คิดโดยผู้ให้บริการขนส่ง', 'ค่าจัดส่งสินค้าคืน', 'เหตุผลในการคืนสินค้าที่ได้รับการประเมินใหม่',
    ];

    $path = sys_get_temp_dir().'/shopee-return-test-'.uniqid().'.xlsx';
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

it('imports a Shopee return case end-to-end against the mirrored order', function () {
    $shop = shopWithReturnableOrder();

    $file = new UploadedFile(writeShopeeReturnXlsx([
        [
            'หมายเลขคำขอคืนเงิน/คืนสินค้า' => '2605RTX1', 'หมายเลขคำสั่งซื้อ' => '2606CXKD11AB',
            'เลข SKU' => 'TS-RED-M', 'จำนวนสินค้าคืน' => '1',
            'ข้อเสนอการคืนเงิน/คืนสินค้า' => 'คืนเงินและคืนสินค้า',
            'สถานะการคืนเงินหรือคืนสินค้า' => 'คืนเงินแล้ว', 'สถานะการส่งสินค้าคืน' => 'จัดส่งสินค้าคืนสำเร็จ',
            'เหตุผลในการขอคืนสินค้า' => 'ได้รับสินค้าผิด', 'หมายเหตุในการคืนสินค้า' => 'ได้รับสีดำ สั่งสีแดง',
            'จำนวนเงินคืนทั้งหมด' => '175.00',
            'เวลายื่นคำขอคืนเงิน/คืนสินค้า' => '2026-05-08 18:45', 'เวลาที่คืนเงิน' => '2026-05-10 13:39',
            'หมายเลขติดตามพัสดุสำหรับส่งคืน' => 'RTH9001',
        ],
    ]), 'Order.return_refund.xls', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeReturnImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    $return = OrderReturn::query()->where('platform_return_id', '2605RTX1')->firstOrFail();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($return->return_type)->toBe(ReturnType::ReturnAndRefund)
        // Courier says delivered — but only Inbound Scan sets รับของกลับแล้ว.
        ->and($return->sub_status)->toBe(ReturnSubStatus::CourierClaimsDelivered)
        ->and($return->refund_amount?->satang)->toBe(17500)
        ->and($return->return_reason)->toBe('ได้รับสินค้าผิด')
        ->and($return->buyer_note)->toBe('ได้รับสีดำ สั่งสีแดง')
        ->and($return->tracking_number)->toBe('RTH9001')
        ->and($return->requested_at?->format('Y-m-d H:i'))->toBe('2026-05-08 11:45')
        ->and($return->refunded_at?->format('Y-m-d H:i'))->toBe('2026-05-10 06:39')
        ->and($return->lines)->toHaveCount(1)
        ->and($return->lines->first()?->qty)->toBe(1);
});

it('detects the Platform closure — ยกเลิกคำขอ moves the Return to ยกเลิกการคืน', function () {
    $shop = shopWithReturnableOrder();
    $row = fn (string $mainStatus): array => [
        'หมายเลขคำขอคืนเงิน/คืนสินค้า' => '2605RTX2', 'หมายเลขคำสั่งซื้อ' => '2606CXKD11AB',
        'เลข SKU' => 'TS-RED-M', 'จำนวนสินค้าคืน' => '1',
        'ข้อเสนอการคืนเงิน/คืนสินค้า' => 'คืนเงินและคืนสินค้า',
        'สถานะการคืนเงินหรือคืนสินค้า' => $mainStatus,
        'เวลายื่นคำขอคืนเงิน/คืนสินค้า' => '2026-05-08 18:45',
    ];

    foreach (['รอการตรวจสอบ', 'ยกเลิกคำขอ'] as $status) {
        $file = new UploadedFile(writeShopeeReturnXlsx([$row($status)]), 'returns.xls', null, null, true);
        app(StartImport::class)->handle($file, ShopeeReturnImporter::class, ['shop_id' => $shop->id]);
    }

    expect(OrderReturn::query()->where('platform_return_id', '2605RTX2')->firstOrFail()->sub_status)
        ->toBe(ReturnSubStatus::Closed);
});

it('holds a row whose main status is unknown — a new Shopee state must never be guessed', function () {
    $shop = shopWithReturnableOrder();

    $file = new UploadedFile(writeShopeeReturnXlsx([
        [
            'หมายเลขคำขอคืนเงิน/คืนสินค้า' => '2605RTX3', 'หมายเลขคำสั่งซื้อ' => '2606CXKD11AB',
            'เลข SKU' => 'TS-RED-M', 'จำนวนสินค้าคืน' => '1',
            'ข้อเสนอการคืนเงิน/คืนสินค้า' => 'คืนเงินและคืนสินค้า',
            'สถานะการคืนเงินหรือคืนสินค้า' => 'สถานะใหม่จาก Shopee',
            'เวลายื่นคำขอคืนเงิน/คืนสินค้า' => '2026-05-08 18:45',
        ],
    ]), 'returns.xls', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeReturnImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and(OrderReturn::query()->count())->toBe(0);
});

it('holds a return whose order has not been imported yet', function () {
    $shop = shopWithReturnableOrder();

    $file = new UploadedFile(writeShopeeReturnXlsx([
        [
            'หมายเลขคำขอคืนเงิน/คืนสินค้า' => '2605RTX4', 'หมายเลขคำสั่งซื้อ' => 'UNKNOWN-ORDER',
            'เลข SKU' => 'TS-RED-M', 'จำนวนสินค้าคืน' => '1',
            'ข้อเสนอการคืนเงิน/คืนสินค้า' => 'คืนเงินและคืนสินค้า',
            'สถานะการคืนเงินหรือคืนสินค้า' => 'รอการตรวจสอบ',
            'เวลายื่นคำขอคืนเงิน/คืนสินค้า' => '2026-05-08 18:45',
        ],
    ]), 'returns.xls', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeReturnImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('UNKNOWN-ORDER')
        ->and(OrderReturn::query()->count())->toBe(0);
});

it('re-importing the same return file is idempotent', function () {
    $shop = shopWithReturnableOrder();
    $rows = [[
        'หมายเลขคำขอคืนเงิน/คืนสินค้า' => '2605RTX5', 'หมายเลขคำสั่งซื้อ' => '2606CXKD11AB',
        'เลข SKU' => 'TS-RED-M', 'จำนวนสินค้าคืน' => '1',
        'ข้อเสนอการคืนเงิน/คืนสินค้า' => 'คืนเงินและคืนสินค้า',
        'สถานะการคืนเงินหรือคืนสินค้า' => 'รอการตรวจสอบ',
        'เวลายื่นคำขอคืนเงิน/คืนสินค้า' => '2026-05-08 18:45',
    ]];

    foreach ([1, 2] as $run) {
        $file = new UploadedFile(writeShopeeReturnXlsx($rows), 'returns.xls', null, null, true);
        app(StartImport::class)->handle($file, ShopeeReturnImporter::class, ['shop_id' => $shop->id]);
    }

    expect(OrderReturn::query()->count())->toBe(1)
        ->and(OrderReturn::query()->firstOrFail()->lines)->toHaveCount(1);
});
