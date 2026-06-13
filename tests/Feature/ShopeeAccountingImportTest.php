<?php

use App\Actions\Imports\StartImport;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\AccountingLineCategory;
use App\Enums\ImportJobStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Imports\ShopeeAccountingImporter;
use App\Models\AccountingEntryLine;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Tenant;
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

// ---------------------------------------------------------------------------
// Fixture: a Shopee "Income" sheet mirroring the real file — header on the 4th
// emitted row, one wide row per Order. The column NAMES match the real file
// exactly (verified against `ref doc/shopee/Accounting shopee 2.xlsx`).
// ---------------------------------------------------------------------------

/** @return list<string> */
function shopeeAccountingHeaders(): array
{
    return [
        'หมายเลขคำสั่งซื้อ',
        'วันที่โอนชำระเงินสำเร็จ',
        'สินค้าราคาปกติ',
        'ส่วนลดสินค้าจากผู้ขาย',
        'จำนวนเงินที่ทำการคืนให้ผู้ซื้อ',
        'ค่าจัดส่งสินค้าที่ออกโดย Shopee',
        'ค่าจัดส่งที่ Shopee ชำระโดยชื่อของคุณ',
        'ค่าคอมมิชชั่น',
        'ค่าบริการ',
        'ค่าธุรกรรมการชำระเงิน',
        'ค่าจัดส่งสินค้าคืน',
        'จำนวนเงินทั้งหมดที่โอนแล้ว (฿)',
    ];
}

/**
 * @param  list<array<string, string>>  $dataRows  header => value
 */
function writeShopeeAccountingXlsx(array $dataRows): string
{
    $headers = shopeeAccountingHeaders();
    $path = sys_get_temp_dir().'/shopee-accounting-'.uniqid().'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    // Sheet 1: a decoy, to prove the importer selects "Income" by name.
    $writer->getCurrentSheet()->setName('Summary');
    $writer->addRow(Row::fromValues(['ignore', 'this', 'sheet']));

    // Sheet 2: the data sheet. 3 non-blank preamble rows → header at emitted 4.
    $writer->addNewSheetAndMakeItCurrent();
    $writer->getCurrentSheet()->setName('Income');
    $writer->addRow(Row::fromValues(['ชื่อผู้ใช้ (ผู้ขาย)', 'จาก', 'ถึง']));
    $writer->addRow(Row::fromValues(['thailumlongshop', '2026-06-01', '2026-06-05']));
    $writer->addRow(Row::fromValues(['ยอดรวม (฿)']));
    $writer->addRow(Row::fromValues($headers));
    foreach ($dataRows as $dataRow) {
        $writer->addRow(Row::fromValues(array_map(
            static fn (string $header): string => (string) ($dataRow[$header] ?? ''),
            $headers,
        )));
    }

    $writer->close();

    return $path;
}

function shopeeAccountingShop(): Shop
{
    return app(CreateShop::class)->handle('Shopee', Platform::Shopee, Location::query()->firstOrFail());
}

function shopeeAccountingOrder(Shop $shop, string $platformOrderId): Order
{
    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => $platformOrderId,
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('1095'),
    ]);
}

/**
 * One reconciling Shopee row: lines sum to the transferred total (843).
 *  สินค้าราคาปกติ 1095 + ship 29 − 29 − commission 123 − service 94 − payment 35 = 843
 *
 * @return array<string, string>
 */
function shopeeReconcilingRow(string $orderId = '260531U2PB43YK', string $settlement = '2026-06-05'): array
{
    return [
        'หมายเลขคำสั่งซื้อ' => $orderId,
        'วันที่โอนชำระเงินสำเร็จ' => $settlement,
        'สินค้าราคาปกติ' => '1095',
        'ค่าจัดส่งสินค้าที่ออกโดย Shopee' => '29',
        'ค่าจัดส่งที่ Shopee ชำระโดยชื่อของคุณ' => '-29',
        'ค่าคอมมิชชั่น' => '-123',
        'ค่าบริการ' => '-94',
        'ค่าธุรกรรมการชำระเงิน' => '-35',
        'จำนวนเงินทั้งหมดที่โอนแล้ว (฿)' => '843',
    ];
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('imports a Shopee Income row end-to-end — signed lines, Actual Net = transferred, Settlement Date set', function () {
    $shop = shopeeAccountingShop();
    $order = shopeeAccountingOrder($shop, '260531U2PB43YK');

    $file = new UploadedFile(
        writeShopeeAccountingXlsx([shopeeReconcilingRow()]),
        'Accounting shopee 2.xlsx', null, null, true,
    );

    $job = app(StartImport::class)->handle($file, ShopeeAccountingImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->error_rows)->toBe(0);

    $order->refresh();
    expect($order->actual_net?->satang)->toBe(84300)              // 843.00 = transferred total
        // Stored as a UTC instant at Bangkok midnight (the app stores every
        // timestamp UTC); the Thai calendar date is 2026-06-05.
        ->and($order->settlement_date?->setTimezone('Asia/Bangkok')->format('Y-m-d'))->toBe('2026-06-05');

    $line = fn (string $sourceField) => $order->accountingEntryLines()
        ->where('source_field', $sourceField)->firstOrFail();

    expect($line('สินค้าราคาปกติ')->category)->toBe(AccountingLineCategory::SaleIncome)
        ->and($line('สินค้าราคาปกติ')->amount->satang)->toBe(109500)
        ->and($line('ค่าคอมมิชชั่น')->category)->toBe(AccountingLineCategory::Commission)
        ->and($line('ค่าคอมมิชชั่น')->amount->satang)->toBe(-12300)
        ->and($line('ค่าธุรกรรมการชำระเงิน')->category)->toBe(AccountingLineCategory::PaymentFee)
        // Every line in the cycle is keyed by the settlement date.
        ->and($order->accountingEntryLines()->where('statement_cycle', '2026-06-05')->count())
        ->toBe($order->accountingEntryLines()->count());
});

it('re-imports the same file idempotently — no double-count, Actual Net unchanged', function () {
    $shop = shopeeAccountingShop();
    $order = shopeeAccountingOrder($shop, '260531U2PB43YK');

    foreach ([1, 2] as $run) {
        $file = new UploadedFile(
            writeShopeeAccountingXlsx([shopeeReconcilingRow()]),
            'Accounting shopee 2.xlsx', null, null, true,
        );
        app(StartImport::class)->handle($file, ShopeeAccountingImporter::class, ['shop_id' => $shop->id]);
    }

    $order->refresh();
    expect($order->actual_net?->satang)->toBe(84300)
        ->and($order->accountingEntryLines()->count())->toBe(6); // 6 non-zero lines, not 12
});

it('appends a later settlement-date cycle without wiping the first — Actual Net sums both', function () {
    $shop = shopeeAccountingShop();
    $order = shopeeAccountingOrder($shop, '260531U2PB43YK');

    // Cycle 1: settles 2026-06-05, net 843.
    app(StartImport::class)->handle(
        new UploadedFile(writeShopeeAccountingXlsx([shopeeReconcilingRow('260531U2PB43YK', '2026-06-05')]), 'a.xlsx', null, null, true),
        ShopeeAccountingImporter::class, ['shop_id' => $shop->id],
    );

    // Cycle 2: a later settlement 2026-07-05, a small self-consistent row net 90.
    app(StartImport::class)->handle(
        new UploadedFile(writeShopeeAccountingXlsx([[
            'หมายเลขคำสั่งซื้อ' => '260531U2PB43YK',
            'วันที่โอนชำระเงินสำเร็จ' => '2026-07-05',
            'สินค้าราคาปกติ' => '100',
            'ค่าคอมมิชชั่น' => '-10',
            'จำนวนเงินทั้งหมดที่โอนแล้ว (฿)' => '90',
        ]]), 'b.xlsx', null, null, true),
        ShopeeAccountingImporter::class, ['shop_id' => $shop->id],
    );

    $order->refresh();
    expect($order->accountingEntryLines()->where('statement_cycle', '2026-06-05')->count())->toBe(6)
        ->and($order->accountingEntryLines()->where('statement_cycle', '2026-07-05')->count())->toBe(2)
        ->and($order->actual_net?->satang)->toBe(84300 + 9000); // 843.00 + 90.00
});

it('fails loud when a row does not reconcile to its transferred total — held, nothing written', function () {
    $shop = shopeeAccountingShop();
    $order = shopeeAccountingOrder($shop, 'SP-MISMATCH');

    // Lines sum to 900 (1000 − 100) but the file claims 800 transferred.
    $file = new UploadedFile(writeShopeeAccountingXlsx([[
        'หมายเลขคำสั่งซื้อ' => 'SP-MISMATCH',
        'วันที่โอนชำระเงินสำเร็จ' => '2026-06-05',
        'สินค้าราคาปกติ' => '1000',
        'ค่าคอมมิชชั่น' => '-100',
        'จำนวนเงินทั้งหมดที่โอนแล้ว (฿)' => '800',
    ]]), 'Accounting shopee 2.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, ShopeeAccountingImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('SP-MISMATCH')
        ->and($order->refresh()->accountingEntryLines()->count())->toBe(0)
        ->and($order->actual_net)->toBeNull();
});

it('holds an unmatched order id — fail-loud, no accounting written', function () {
    $shop = shopeeAccountingShop();
    // No Order with this platform_order_id exists.

    $file = new UploadedFile(
        writeShopeeAccountingXlsx([shopeeReconcilingRow('GHOST-ORDER')]),
        'Accounting shopee 2.xlsx', null, null, true,
    );

    $job = app(StartImport::class)->handle($file, ShopeeAccountingImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('GHOST-ORDER');
});

it('does not match another tenant\'s Order — cross-tenant isolation', function () {
    // Tenant B owns the Order with this platform_order_id.
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);
    $shopB = app(CreateShop::class)->handle('Shopee-B', Platform::Shopee, Location::query()->firstOrFail());
    shopeeAccountingOrder($shopB, '260531U2PB43YK');
    app(TenantContext::class)->forget();

    // Tenant A imports its own Shop's accounting referencing the same order id.
    app(TenantContext::class)->set(Tenant::query()->where('name', 'A')->firstOrFail());
    Storage::fake('local');
    actingAs(User::factory()->create());
    $shopA = shopeeAccountingShop();

    $file = new UploadedFile(
        writeShopeeAccountingXlsx([shopeeReconcilingRow('260531U2PB43YK')]),
        'Accounting shopee 2.xlsx', null, null, true,
    );

    $job = app(StartImport::class)->handle($file, ShopeeAccountingImporter::class, ['shop_id' => $shopA->id]);
    $job->refresh();

    // Tenant A cannot see Tenant B's Order — held as unmatched, nothing written.
    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        // Tenant A owns no Order, so nothing was matched or written.
        ->and(Order::query()->count())->toBe(0)
        ->and(AccountingEntryLine::query()->count())->toBe(0);
});
