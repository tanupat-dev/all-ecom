<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Imports\StartImport;
use App\Actions\Listings\CreateListing;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Imports\TiktokOrderImporter;
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

function tiktokShop(): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $shop = app(CreateShop::class)->handle('tiktok1', Platform::Tiktok, $location);

    $product = app(CreateProduct::class)->handle('เสื้อยืด', [
        ['master_sku' => 'TS-RED-M', 'name' => 'แดง / M', 'list_price' => Money::fromBaht('199')],
        ['master_sku' => 'TS-RED-L', 'name' => 'แดง / L', 'list_price' => Money::fromBaht('199')],
    ]);
    app(CreateListing::class)->handle($shop, $product);

    return $shop;
}

/**
 * The real export's 63 headers (ref doc/tiktok/All order tiktok.csv).
 *
 * @return list<string>
 */
function tiktokOrderHeaders(): array
{
    return [
        'Order ID', 'Order Status', 'Order Substatus', 'Cancelation/Return Type', 'Normal or Pre-order',
        'SKU ID', 'Seller SKU', 'Product Name', 'Variation', 'Quantity', 'Sku Quantity of return',
        'SKU Unit Original Price', 'SKU Subtotal Before Discount', 'SKU Platform Discount',
        'SKU Seller Discount', 'SKU Subtotal After Discount', 'Shipping Fee After Discount',
        'Original Shipping Fee', 'Shipping Fee Seller Discount', 'Shipping Fee Platform Discount',
        'Payment platform discount', 'Taxes', 'Order Amount', 'Order Refund Amount', 'Created Time',
        'Paid Time', 'RTS Time', 'Shipped Time', 'Delivered Time', 'Cancelled Time', 'Cancel By',
        'Cancel Reason', 'Fulfillment Type', 'Warehouse Name', 'Tracking ID', 'Delivery Option',
        'Shipping Provider Name', 'Buyer Message', 'Buyer Username', 'Recipient', 'Phone #', 'Zipcode',
        'Country', 'Province', 'District', 'Districts', 'Detail Address',
        'Additional address information', 'Payment Method', 'Weight(kg)', 'Product Category',
        'Package ID', 'Seller Note', 'Checked Status', 'Checked Marked by', 'Request Tax Invoice',
        'Tax Info - Buyer Tax ID', 'Tax Info - Type', 'Tax Info - Full Name of Buyer',
        'Tax Info - Email', 'Tax Info - Phone Number', 'Tax Info - Registered Address',
        'Tax Info - Address Type',
    ];
}

/**
 * Synthetic rows under the real headers. TikTok pads timestamp cells with
 * a trailing tab — the fixture reproduces that quirk.
 *
 * @param  list<array<string, string>>  $rows  header => value, unset = ''
 */
function writeTiktokCsv(array $rows): string
{
    $headers = tiktokOrderHeaders();
    $path = sys_get_temp_dir().'/tiktok-import-test-'.uniqid().'.csv';
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

/**
 * The same export as xlsx — whichever format the platform serves that
 * day, every importer must take it.
 *
 * @param  list<array<string, string>>  $rows
 */
function writeTiktokXlsx(array $rows): string
{
    $headers = tiktokOrderHeaders();
    $path = sys_get_temp_dir().'/tiktok-import-test-'.uniqid().'.xlsx';
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

it('maps every observed TikTok substatus', function () {
    $mapper = app(TiktokOrderImporter::class);

    expect($mapper->map('รอจัดส่ง'))->toBe(OrderStatus::AwaitingPack)
        ->and($mapper->map('อยู่ระหว่างขนส่ง'))->toBe(OrderStatus::InTransit)
        ->and($mapper->map('จัดส่งสำเร็จ'))->toBe(OrderStatus::Delivered)
        ->and($mapper->map('เสร็จสมบูรณ์'))->toBe(OrderStatus::Completed)
        ->and($mapper->map('ยกเลิกแล้ว'))->toBe(OrderStatus::Cancelled);
});

it('fails loud on a TikTok substatus it has never seen', function () {
    app(TiktokOrderImporter::class)->map('คืนสินค้าบางส่วน');
})->throws(UnmappedPlatformStatusException::class, 'ระบบไม่รองรับ');

it('imports the same TikTok export delivered as xlsx — the format never matters to the importer', function () {
    $shop = tiktokShop();

    $file = new UploadedFile(writeTiktokXlsx([
        [
            'Order ID' => '579xlsx1', 'Order Status' => 'เสร็จสมบูรณ์', 'Order Substatus' => 'เสร็จสมบูรณ์',
            'Seller SKU' => 'TS-RED-M', 'Quantity' => '1',
            'SKU Subtotal Before Discount' => '199', 'SKU Seller Discount' => '0',
            'Created Time' => "05/06/2026 10:21:10\t",
        ],
    ]), 'All order tiktok.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokOrderImporter::class, ['shop_id' => $shop->id]);

    expect($job->refresh()->status)->toBe(ImportJobStatus::Completed)
        ->and(Order::query()->where('platform_order_id', '579xlsx1')->firstOrFail()->total?->satang)->toBe(19900);
});

it('imports a TikTok CSV: exact subtotals win when the seller discount does not divide per unit', function () {
    $shop = tiktokShop();

    $file = new UploadedFile(writeTiktokCsv([
        [
            'Order ID' => '579xx111', 'Order Status' => 'เสร็จสมบูรณ์', 'Order Substatus' => 'เสร็จสมบูรณ์',
            'Seller SKU' => 'TS-RED-M', 'Quantity' => '6',
            'SKU Subtotal Before Discount' => '234', 'SKU Seller Discount' => '11',
            'SKU Platform Discount' => '5', 'SKU Subtotal After Discount' => '218',
            'Created Time' => "05/06/2026 10:21:10\t", 'Paid Time' => "05/06/2026 10:22:00\t",
            'Shipped Time' => "05/06/2026 12:30:47\t", 'Delivered Time' => "07/06/2026 15:00:00\t",
            'Tracking ID' => 'TT77001', 'Buyer Username' => 'tt_buyer',
        ],
        [
            'Order ID' => '579xx222', 'Order Status' => 'ยกเลิกแล้ว', 'Order Substatus' => 'ยกเลิกแล้ว',
            'Seller SKU' => 'TS-RED-L', 'Quantity' => '1',
            'SKU Subtotal Before Discount' => '199', 'SKU Seller Discount' => '0',
            'Created Time' => "05/06/2026 09:00:00\t", 'Cancelled Time' => "05/06/2026 09:30:00\t",
        ],
    ]), 'All order tiktok.csv', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokOrderImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    $completed = Order::query()->where('platform_order_id', '579xx111')->firstOrFail();
    $cancelled = Order::query()->where('platform_order_id', '579xx222')->firstOrFail();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($completed->status)->toBe(OrderStatus::Completed)
        // ฿234 − ฿11 = ฿223.00 exact; per-unit floors to ฿37.16 — the
        // platform discount (subsidy) never reduces it.
        ->and($completed->lines->first()?->line_total?->satang)->toBe(22300)
        ->and($completed->lines->first()?->unit_price?->satang)->toBe(3716)
        ->and($completed->total?->satang)->toBe(22300)
        ->and($completed->tracking_number)->toBe('TT77001')
        ->and($completed->buyer_name)->toBe('tt_buyer')
        // TikTok exposes the delivered timestamp (its payout anchor).
        ->and($completed->delivered_date?->format('Y-m-d H:i'))->toBe('2026-06-07 08:00')
        ->and($completed->completed_date)->toBeNull()
        ->and($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelled->cancelled_date?->format('Y-m-d H:i'))->toBe('2026-06-05 02:30');
});
