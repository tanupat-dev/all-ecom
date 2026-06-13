<?php

use App\Actions\Imports\StartImport;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\AccountingLineCategory;
use App\Enums\ImportJobStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Imports\TiktokAccountingImporter;
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

const TIKTOK_CYCLE = '2026/04/01-2026/06/05';

// ---------------------------------------------------------------------------
// Fixture: TikTok's "รายละเอียดคำสั่งซื้อ" (order details) sheet — header on
// row 1, one wide row per order. The column NAMES match the real file exactly
// (verified against `ref doc/tiktok/Accounting tiktok.xlsx`). The file's period
// lives in a separate `รายงาน` sheet the streaming pipeline never reaches, so
// the statement cycle arrives via ImportJob context (statement_cycle).
// ---------------------------------------------------------------------------

/** @return list<string> */
function tiktokAccountingHeaders(): array
{
    return [
        'หมายเลขคำสั่งซื้อ/การปรับ',                          // 0  order/adjustment id
        'ประเภทธุรกรรม',                                      // 1  transaction type
        'จำนวนเงินที่ชำระทั้งหมด',                            // 5  net settled = transferred_total
        'ยอดรวมค่าสินค้าก่อนหักส่วนลด',                       // 8  gross goods -> SaleIncome
        'ส่วนลดจากร้านค้า',                                   // 9  seller discount -> MarketingFee
        'ยอดรวมเงินคืนก่อนหักส่วนลดจากร้านค้า',               // 11 refund -> Refund
        'เงินคืนจากส่วนลดร้านค้า',                            // 12 refund of discount -> Refund
        'ค่าธรรมเนียมคำสั่งซื้อ',                             // 14 transaction fee -> PaymentFee
        'ค่าคอมมิชชั่น TikTok Shop',                          // 15 commission -> Commission
        'ค่าธรรมเนียมการจัดส่งจริง',                          // 18 actual shipping -> ShippingSellerPaid
        'ส่วนลดค่าธรรมเนียมการจัดส่งจากแพลตฟอร์ม',            // 19 platform ship discount -> ShippingSellerPaid
        'ค่าคอมมิชชั่นแอฟฟิลิเอต',                            // 26 affiliate commission -> AffiliateFee
        'ค่าธรรมเนียมสนับสนุนการเติบโตของร้านค้า',            // 43 growth fee -> Commission
        'ค่าธรรมเนียมโครงสร้างพื้นฐาน',                       // 44 infrastructure fee -> Commission
        'จำนวนการปรับยอด',                                   // 51 adjustment amount
        'หมายเลขคำสั่งซื้อที่เกี่ยวข้อง',                     // 52 related order id (compensation rows)
    ];
}

/**
 * @param  list<array<string, string>>  $dataRows  header => value
 */
function writeTiktokAccountingXlsx(array $dataRows): string
{
    $headers = tiktokAccountingHeaders();
    $path = sys_get_temp_dir().'/tiktok-accounting-'.uniqid().'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    // Sheet 1: a decoy, to prove the importer selects the data sheet by name.
    $writer->getCurrentSheet()->setName('รายงาน');
    $writer->addRow(Row::fromValues(['', 'ช่วงเวลา', '', '', '', TIKTOK_CYCLE]));

    // Sheet 2: the data sheet. Header on row 1, data from row 2.
    $writer->addNewSheetAndMakeItCurrent();
    $writer->getCurrentSheet()->setName('รายละเอียดคำสั่งซื้อ');
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

function tiktokAccountingShop(): Shop
{
    return app(CreateShop::class)->handle('TikTok', Platform::Tiktok, Location::query()->firstOrFail());
}

function tiktokAccountingOrder(Shop $shop, string $platformOrderId): Order
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
 * One reconciling TikTok order row (mirrors real row 3, net 840.88):
 *  gross 1095 − sellerDiscount 31 − orderFee 34.15 − commission 102.46
 *  − shipping 40 + platformShipDiscount 40 − growth 85.44 − infra 1.07 = 840.88
 *
 * @return array<string, string>
 */
function tiktokReconcilingRow(string $orderId = '584276914182391588'): array
{
    return [
        'หมายเลขคำสั่งซื้อ/การปรับ' => $orderId,
        'ประเภทธุรกรรม' => 'คำสั่งซื้อ',
        'จำนวนเงินที่ชำระทั้งหมด' => '840.88',
        'ยอดรวมค่าสินค้าก่อนหักส่วนลด' => '1095',
        'ส่วนลดจากร้านค้า' => '-31',
        'ค่าธรรมเนียมคำสั่งซื้อ' => '-34.15',
        'ค่าคอมมิชชั่น TikTok Shop' => '-102.46',
        'ค่าธรรมเนียมการจัดส่งจริง' => '-40',
        'ส่วนลดค่าธรรมเนียมการจัดส่งจากแพลตฟอร์ม' => '40',
        'ค่าธรรมเนียมสนับสนุนการเติบโตของร้านค้า' => '-85.44',
        'ค่าธรรมเนียมโครงสร้างพื้นฐาน' => '-1.07',
        'หมายเลขคำสั่งซื้อที่เกี่ยวข้อง' => $orderId,
    ];
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('imports a TikTok order row end-to-end — signed leaf lines, Actual Net = net settled, no settlement date', function () {
    $shop = tiktokAccountingShop();
    $order = tiktokAccountingOrder($shop, '584276914182391588');

    $file = new UploadedFile(
        writeTiktokAccountingXlsx([tiktokReconcilingRow()]),
        'Accounting tiktok.xlsx', null, null, true,
    );

    $job = app(StartImport::class)->handle($file, TiktokAccountingImporter::class, [
        'shop_id' => $shop->id,
        'statement_cycle' => TIKTOK_CYCLE,
    ]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->error_rows)->toBe(0);

    $order->refresh();
    expect($order->actual_net?->satang)->toBe(84088)        // 840.88 = net settled
        ->and($order->settlement_date)->toBeNull();          // TikTok exposes no settlement date

    $line = fn (string $sourceField) => $order->accountingEntryLines()
        ->where('source_field', $sourceField)->firstOrFail();

    expect($line('ยอดรวมค่าสินค้าก่อนหักส่วนลด')->category)->toBe(AccountingLineCategory::SaleIncome)
        ->and($line('ยอดรวมค่าสินค้าก่อนหักส่วนลด')->amount->satang)->toBe(109500)
        ->and($line('ส่วนลดจากร้านค้า')->category)->toBe(AccountingLineCategory::MarketingFee)
        ->and($line('ส่วนลดจากร้านค้า')->amount->satang)->toBe(-3100)
        ->and($line('ค่าคอมมิชชั่น TikTok Shop')->category)->toBe(AccountingLineCategory::Commission)
        ->and($line('ค่าคอมมิชชั่น TikTok Shop')->amount->satang)->toBe(-10246)
        ->and($line('ค่าธรรมเนียมคำสั่งซื้อ')->category)->toBe(AccountingLineCategory::PaymentFee)
        // A zero column (affiliate commission here) is never emitted as a line.
        ->and($order->accountingEntryLines()->where('source_field', 'ค่าคอมมิชชั่นแอฟฟิลิเอต')->exists())->toBeFalse()
        // Every line is keyed by the statement cycle from context.
        ->and($order->accountingEntryLines()->where('statement_cycle', TIKTOK_CYCLE)->count())
        ->toBe($order->accountingEntryLines()->count())
        ->and($order->accountingEntryLines()->count())->toBe(8); // 8 non-zero leaf lines
});

it('re-imports the same file idempotently — no double-count, Actual Net unchanged', function () {
    $shop = tiktokAccountingShop();
    $order = tiktokAccountingOrder($shop, '584276914182391588');

    foreach ([1, 2] as $run) {
        $file = new UploadedFile(
            writeTiktokAccountingXlsx([tiktokReconcilingRow()]),
            'Accounting tiktok.xlsx', null, null, true,
        );
        app(StartImport::class)->handle($file, TiktokAccountingImporter::class, [
            'shop_id' => $shop->id,
            'statement_cycle' => TIKTOK_CYCLE,
        ]);
    }

    $order->refresh();
    expect($order->actual_net?->satang)->toBe(84088)
        ->and($order->accountingEntryLines()->count())->toBe(8); // 8 lines, not 16
});

it('attaches a platform-compensation row to its related order as an adjustment line', function () {
    $shop = tiktokAccountingShop();
    // The related order exists but has no sale row of its own in this file.
    $related = tiktokAccountingOrder($shop, '583754610438407477');

    $file = new UploadedFile(writeTiktokAccountingXlsx([[
        'หมายเลขคำสั่งซื้อ/การปรับ' => '7641963103148558087', // the adjustment's own id
        'ประเภทธุรกรรม' => 'การชดเชยจากแพลตฟอร์ม',
        'จำนวนเงินที่ชำระทั้งหมด' => '38',
        'จำนวนการปรับยอด' => '38',
        'หมายเลขคำสั่งซื้อที่เกี่ยวข้อง' => '583754610438407477',
    ]]), 'Accounting tiktok.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokAccountingImporter::class, [
        'shop_id' => $shop->id,
        'statement_cycle' => TIKTOK_CYCLE,
    ]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->error_rows)->toBe(0);

    $related->refresh();
    $line = $related->accountingEntryLines()->where('source_field', 'จำนวนการปรับยอด')->firstOrFail();
    expect($line->category)->toBe(AccountingLineCategory::Other)
        ->and($line->amount->satang)->toBe(3800)
        ->and($related->actual_net?->satang)->toBe(3800)
        ->and($related->accountingEntryLines()->count())->toBe(1);
});

it('fails loud when a row does not reconcile to its net settled total — held, nothing written', function () {
    $shop = tiktokAccountingShop();
    $order = tiktokAccountingOrder($shop, 'TT-MISMATCH');

    // Leaf lines sum to 900 (1000 − 100) but the file reports 800 net settled.
    $file = new UploadedFile(writeTiktokAccountingXlsx([[
        'หมายเลขคำสั่งซื้อ/การปรับ' => 'TT-MISMATCH',
        'ประเภทธุรกรรม' => 'คำสั่งซื้อ',
        'จำนวนเงินที่ชำระทั้งหมด' => '800',
        'ยอดรวมค่าสินค้าก่อนหักส่วนลด' => '1000',
        'ค่าคอมมิชชั่น TikTok Shop' => '-100',
        'หมายเลขคำสั่งซื้อที่เกี่ยวข้อง' => 'TT-MISMATCH',
    ]]), 'Accounting tiktok.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokAccountingImporter::class, [
        'shop_id' => $shop->id,
        'statement_cycle' => TIKTOK_CYCLE,
    ]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('TT-MISMATCH')
        ->and($order->refresh()->accountingEntryLines()->count())->toBe(0)
        ->and($order->actual_net)->toBeNull();
});

it('fails loud when statement_cycle is absent from context — no cycle invented, nothing written', function () {
    $shop = tiktokAccountingShop();
    $order = tiktokAccountingOrder($shop, '584276914182391588');

    $file = new UploadedFile(
        writeTiktokAccountingXlsx([tiktokReconcilingRow()]),
        'Accounting tiktok.xlsx', null, null, true,
    );

    // No statement_cycle in context — the period lives in a sheet the stream
    // never reads, so it MUST be supplied; absent it, every row is held.
    $job = app(StartImport::class)->handle($file, TiktokAccountingImporter::class, [
        'shop_id' => $shop->id,
    ]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('statement_cycle')
        ->and($order->refresh()->accountingEntryLines()->count())->toBe(0)
        ->and($order->actual_net)->toBeNull();
});

it('holds an unmatched order id — fail-loud, no accounting written', function () {
    $shop = tiktokAccountingShop();
    // No Order with this platform_order_id exists.

    $file = new UploadedFile(
        writeTiktokAccountingXlsx([tiktokReconcilingRow('GHOST-ORDER')]),
        'Accounting tiktok.xlsx', null, null, true,
    );

    $job = app(StartImport::class)->handle($file, TiktokAccountingImporter::class, [
        'shop_id' => $shop->id,
        'statement_cycle' => TIKTOK_CYCLE,
    ]);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('GHOST-ORDER');
});

it('does not match another tenant\'s Order — cross-tenant isolation', function () {
    // Tenant B owns the Order with this platform_order_id.
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);
    $shopB = app(CreateShop::class)->handle('TikTok-B', Platform::Tiktok, Location::query()->firstOrFail());
    tiktokAccountingOrder($shopB, '584276914182391588');
    app(TenantContext::class)->forget();

    // Tenant A imports its own Shop's accounting referencing the same order id.
    app(TenantContext::class)->set(Tenant::query()->where('name', 'A')->firstOrFail());
    Storage::fake('local');
    actingAs(User::factory()->create());
    $shopA = tiktokAccountingShop();

    $file = new UploadedFile(
        writeTiktokAccountingXlsx([tiktokReconcilingRow('584276914182391588')]),
        'Accounting tiktok.xlsx', null, null, true,
    );

    $job = app(StartImport::class)->handle($file, TiktokAccountingImporter::class, [
        'shop_id' => $shopA->id,
        'statement_cycle' => TIKTOK_CYCLE,
    ]);
    $job->refresh();

    // Tenant A cannot see Tenant B's Order — held as unmatched, nothing written.
    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(Order::query()->count())->toBe(0)
        ->and(AccountingEntryLine::query()->count())->toBe(0);
});
