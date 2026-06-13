<?php

use App\Actions\Imports\StartImport;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\AccountingLineCategory;
use App\Enums\ImportJobStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Imports\LazadaAccountingImporter;
use App\Models\AccountingEntryLine;
use App\Models\ImportJob;
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
// Fixture: a Lazada "Income Overview" journal mirroring the real file — single
// sheet, header on row 1, ONE fee/income leg per row (many rows per Order under
// one รหัสรอบบิล). Column NAMES match the real file exactly (verified against
// `ref doc/lazada/Accounting lazada.xlsx`).
// ---------------------------------------------------------------------------

/** @return list<string> */
function lazadaAccountingHeaders(): array
{
    return [
        'รหัสรอบบิล',
        'ชื่อรายการธุรกรรม',
        'จำนวนเงิน(รวมภาษี)',
        'วันที่ปรับปรุงเข้ายอดของฉัน',
        'หมายเลขคำสั่งซื้อ',
        'WHT Amount',
        'WHT รวมอยู่ในจำนวนเงินแล้ว',
    ];
}

/**
 * @param  list<array<string, string>>  $journalRows  header => value
 */
function writeLazadaAccountingXlsx(array $journalRows): string
{
    $headers = lazadaAccountingHeaders();
    $path = sys_get_temp_dir().'/lazada-accounting-'.uniqid().'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Income Overview');
    $writer->addRow(Row::fromValues($headers)); // header on row 1
    foreach ($journalRows as $journalRow) {
        $writer->addRow(Row::fromValues(array_map(
            static fn (string $header): string => (string) ($journalRow[$header] ?? ''),
            $headers,
        )));
    }

    $writer->close();

    return $path;
}

/**
 * One journal leg row. Defaults: WHT 0 / NO (no separate WHT line).
 *
 * @return array<string, string>
 */
function lazadaLeg(
    string $transactionName,
    string $amount,
    string $orderId = 'LAZ-ORDER-1',
    string $cycle = 'THJ2HAHL-2026-0530',
    string $settlement = '31 May 2026',
    string $whtAmount = '0',
    string $whtIncluded = 'NO',
): array {
    return [
        'รหัสรอบบิล' => $cycle,
        'ชื่อรายการธุรกรรม' => $transactionName,
        'จำนวนเงิน(รวมภาษี)' => $amount,
        'วันที่ปรับปรุงเข้ายอดของฉัน' => $settlement,
        'หมายเลขคำสั่งซื้อ' => $orderId,
        'WHT Amount' => $whtAmount,
        'WHT รวมอยู่ในจำนวนเงินแล้ว' => $whtIncluded,
    ];
}

/**
 * A full 4-leg journal for one Order/cycle: 249 − 34.64 − 15.99 − 8.22 = 190.15.
 *
 * @return list<array<string, string>>
 */
function lazadaOrderJournal(string $orderId = 'LAZ-ORDER-1', string $cycle = 'THJ2HAHL-2026-0530', string $settlement = '31 May 2026'): array
{
    return [
        lazadaLeg('ยอดรวมค่าสินค้า', '249', $orderId, $cycle, $settlement),
        lazadaLeg('หักค่าธรรมเนียมการขายสินค้า', '-34.64', $orderId, $cycle, $settlement),
        lazadaLeg('ค่าโปรแกรมส่งฟรีพิเศษกับลาซาด้า', '-15.99', $orderId, $cycle, $settlement),
        lazadaLeg('ค่าธรรมเนียมการชำระเงิน', '-8.22', $orderId, $cycle, $settlement),
    ];
}

function lazadaAccountingShop(): Shop
{
    return app(CreateShop::class)->handle('Lazada', Platform::Lazada, Location::query()->firstOrFail());
}

function lazadaAccountingOrder(Shop $shop, string $platformOrderId): Order
{
    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => $platformOrderId,
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('249'),
    ]);
}

/**
 * @param  list<array<string, string>>  $journalRows
 */
function importLazada(Shop $shop, array $journalRows): ImportJob
{
    $file = new UploadedFile(
        writeLazadaAccountingXlsx($journalRows),
        'Accounting lazada.xlsx', null, null, true,
    );

    return app(StartImport::class)->handle($file, LazadaAccountingImporter::class, ['shop_id' => $shop->id]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('collapses a multi-row Lazada journal into one Accounting Entry — signed lines, Actual Net = sum, Settlement Date set', function () {
    $shop = lazadaAccountingShop();
    $order = lazadaAccountingOrder($shop, 'LAZ-ORDER-1');

    $job = importLazada($shop, lazadaOrderJournal())->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->error_rows)->toBe(0);

    $order->refresh();
    expect($order->actual_net?->satang)->toBe(19015) // 249 − 34.64 − 15.99 − 8.22 = 190.15
        ->and($order->settlement_date?->setTimezone('Asia/Bangkok')->format('Y-m-d'))->toBe('2026-05-31')
        ->and($order->accountingEntryLines()->count())->toBe(4)
        ->and($order->accountingEntryLines()->where('statement_cycle', 'THJ2HAHL-2026-0530')->count())->toBe(4);

    $line = fn (string $sourceField) => $order->accountingEntryLines()
        ->where('source_field', $sourceField)->firstOrFail();

    expect($line('ยอดรวมค่าสินค้า')->category)->toBe(AccountingLineCategory::SaleIncome)
        ->and($line('ยอดรวมค่าสินค้า')->amount->satang)->toBe(24900)
        ->and($line('หักค่าธรรมเนียมการขายสินค้า')->category)->toBe(AccountingLineCategory::Commission)
        ->and($line('หักค่าธรรมเนียมการขายสินค้า')->amount->satang)->toBe(-3464)
        ->and($line('ค่าโปรแกรมส่งฟรีพิเศษกับลาซาด้า')->category)->toBe(AccountingLineCategory::ShippingSellerPaid)
        ->and($line('ค่าโปรแกรมส่งฟรีพิเศษกับลาซาด้า')->amount->satang)->toBe(-1599)
        ->and($line('ค่าธรรมเนียมการชำระเงิน')->category)->toBe(AccountingLineCategory::PaymentFee)
        ->and($line('ค่าธรรมเนียมการชำระเงิน')->amount->satang)->toBe(-822);
});

it('appends a later cycle for the same Order — Actual Net sums across both cycles', function () {
    $shop = lazadaAccountingShop();
    $order = lazadaAccountingOrder($shop, 'LAZ-ORDER-1');

    // Cycle 1: the sale journal settles 31 May, net 190.15.
    importLazada($shop, lazadaOrderJournal());

    // Cycle 2: a later return deduction posts under a NEW รหัสรอบบิล, −50.00.
    importLazada($shop, [
        lazadaLeg('หักเงินค่าสินค้า (คืนสินค้า)', '-50', 'LAZ-ORDER-1', 'THJ2HAHL-2026-0610', '11 Jun 2026'),
    ]);

    $order->refresh();
    expect($order->accountingEntryLines()->where('statement_cycle', 'THJ2HAHL-2026-0530')->count())->toBe(4)
        ->and($order->accountingEntryLines()->where('statement_cycle', 'THJ2HAHL-2026-0610')->count())->toBe(1)
        ->and($order->accountingEntryLines()->where('source_field', 'หักเงินค่าสินค้า (คืนสินค้า)')->firstOrFail()->category)
        ->toBe(AccountingLineCategory::Refund)
        ->and($order->actual_net?->satang)->toBe(19015 - 5000) // 190.15 − 50.00 = 140.15
        // First non-null settlement date wins (no-null-overwrite) — still cycle 1.
        ->and($order->settlement_date?->setTimezone('Asia/Bangkok')->format('Y-m-d'))->toBe('2026-05-31');
});

it('re-imports the same Lazada file idempotently — no double-count', function () {
    $shop = lazadaAccountingShop();
    $order = lazadaAccountingOrder($shop, 'LAZ-ORDER-1');

    foreach ([1, 2] as $run) {
        importLazada($shop, lazadaOrderJournal());
    }

    $order->refresh();
    expect($order->actual_net?->satang)->toBe(19015)
        ->and($order->accountingEntryLines()->count())->toBe(4); // 4 legs, not 8
});

it('holds an unknown ชื่อรายการธุรกรรม fail-loud — nothing written for that order (the only safety net)', function () {
    $shop = lazadaAccountingShop();
    $order = lazadaAccountingOrder($shop, 'LAZ-BAD');

    // The order's single journal row carries a transaction name not in the map.
    $job = importLazada($shop, [
        lazadaLeg('ค่าธรรมเนียมลึกลับที่ไม่เคยเห็น', '-12.34', 'LAZ-BAD'),
    ])->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(1)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('ค่าธรรมเนียมลึกลับที่ไม่เคยเห็น')
        ->and($order->refresh()->accountingEntryLines()->count())->toBe(0)
        ->and($order->actual_net)->toBeNull();
});

it('emits a separate TaxWithheld line when WHT Amount is non-zero and not included in the amount', function () {
    $shop = lazadaAccountingShop();
    $order = lazadaAccountingOrder($shop, 'LAZ-WHT');

    // Sale 100.00, plus a 7.00 WHT that is NOT already inside the amount.
    $job = importLazada($shop, [
        lazadaLeg('ยอดรวมค่าสินค้า', '100', 'LAZ-WHT', 'THJ2HAHL-2026-0530', '31 May 2026', '7', 'NO'),
    ])->refresh();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($job->error_rows)->toBe(0);

    $order->refresh();
    $wht = $order->accountingEntryLines()->where('source_field', 'WHT Amount')->firstOrFail();

    expect($wht->category)->toBe(AccountingLineCategory::TaxWithheld)
        ->and($wht->amount->satang)->toBe(-700)                 // signed to reduce net
        ->and($order->accountingEntryLines()->count())->toBe(2) // sale_income + tax_withheld
        ->and($order->actual_net?->satang)->toBe(9300);         // 100.00 − 7.00
});

it('does NOT emit a WHT line when the withholding is already included in the amount', function () {
    $shop = lazadaAccountingShop();
    $order = lazadaAccountingOrder($shop, 'LAZ-WHT-INCL');

    importLazada($shop, [
        lazadaLeg('ยอดรวมค่าสินค้า', '100', 'LAZ-WHT-INCL', 'THJ2HAHL-2026-0530', '31 May 2026', '7', 'YES'),
    ]);

    $order->refresh();
    expect($order->accountingEntryLines()->where('source_field', 'WHT Amount')->count())->toBe(0)
        ->and($order->accountingEntryLines()->count())->toBe(1)
        ->and($order->actual_net?->satang)->toBe(10000); // 100.00, WHT already inside
});

it('holds an unmatched order id — fail-loud, nothing written', function () {
    $shop = lazadaAccountingShop();
    // No Order with this platform_order_id exists.

    $job = importLazada($shop, lazadaOrderJournal('LAZ-GHOST'))->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBeGreaterThan(0)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('LAZ-GHOST')
        ->and(AccountingEntryLine::query()->count())->toBe(0);
});

it('does not match another tenant\'s Order — cross-tenant isolation', function () {
    // Tenant B owns the Order with this platform_order_id.
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);
    $shopB = app(CreateShop::class)->handle('Lazada-B', Platform::Lazada, Location::query()->firstOrFail());
    lazadaAccountingOrder($shopB, 'LAZ-ORDER-1');
    app(TenantContext::class)->forget();

    // Tenant A imports its own Shop's accounting referencing the same order id.
    app(TenantContext::class)->set(Tenant::query()->where('name', 'A')->firstOrFail());
    Storage::fake('local');
    actingAs(User::factory()->create());
    $shopA = lazadaAccountingShop();

    $job = importLazada($shopA, lazadaOrderJournal('LAZ-ORDER-1'))->refresh();

    // Tenant A cannot see Tenant B's Order — every leg is held as unmatched,
    // nothing is written across the tenant boundary.
    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBeGreaterThan(0)
        ->and(Order::query()->count())->toBe(0)
        ->and(AccountingEntryLine::query()->count())->toBe(0);
});
