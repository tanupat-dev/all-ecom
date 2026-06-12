<?php

use App\Actions\Imports\StartImport;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Imports\NormalizedOrder;
use App\Imports\TiktokReturnImporter;
use App\Models\OrderReturn;
use App\Models\Shop;
use App\Models\User;
use App\Support\Money;
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

/** tiktokShop() comes from TiktokOrderImportTest. */
function tiktokShopWithOrder(): Shop
{
    $shop = tiktokShop();
    app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: '579xx900',
        status: OrderStatus::Completed,
        lines: [
            ['variant' => variantBySku('TS-RED-M'), 'qty' => 1, 'unit_price' => Money::fromBaht('249')],
            ['variant' => variantBySku('TS-RED-L'), 'qty' => 2, 'unit_price' => Money::fromBaht('234')],
        ],
    ));

    return $shop;
}

/**
 * The real export's 25 headers, CSV, one row per SKU line.
 *
 * @param  list<array<string, string>>  $rows
 */
function writeTiktokReturnCsv(array $rows): string
{
    $headers = [
        'Return Order ID', 'Order ID', 'Order Amount', 'Order Status', 'Order Substatus',
        'Payment Method', 'SKU ID', 'Seller SKU', 'Product Name', 'SKU Name', 'Buyer Username',
        'Return Type', 'Time Requested', 'Return Reason', 'Return unit price', 'Return Quantity',
        'Return Logistics Tracking ID', 'Return Status', 'Return Sub Status', 'Refund Time',
        'Dispute Status', 'Appeal Status', 'Compensation Status', 'Compensation Amount', 'Buyer Note',
    ];

    $path = sys_get_temp_dir().'/tiktok-return-test-'.uniqid().'.csv';
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

it('imports a multi-line TikTok return: refund sums across rows, journey awaits the scan', function () {
    $shop = tiktokShopWithOrder();

    $file = new UploadedFile(writeTiktokReturnCsv([
        [
            'Return Order ID' => 'TTR-1', 'Order ID' => '579xx900', 'Seller SKU' => 'TS-RED-M',
            'Return Type' => 'Return and refund', 'Return Quantity' => '1', 'Return unit price' => '฿249.00',
            'Return Status' => 'Completed', 'Time Requested' => "07/05/2026 20:58:44\t",
            'Refund Time' => "08/05/2026 16:58:12\t", 'Return Reason' => 'สินค้าไม่ตรงกับคำอธิบาย',
            'Buyer Note' => 'สีไม่ตรง', 'Return Logistics Tracking ID' => 'TTRL01',
        ],
        [
            'Return Order ID' => 'TTR-1', 'Order ID' => '579xx900', 'Seller SKU' => 'TS-RED-L',
            'Return Type' => 'Return and refund', 'Return Quantity' => '2', 'Return unit price' => '฿234.00',
            'Return Status' => 'Completed', 'Time Requested' => "07/05/2026 20:58:44\t",
            'Refund Time' => "08/05/2026 16:58:12\t", 'Return Reason' => 'สินค้าไม่ตรงกับคำอธิบาย',
        ],
    ]), 'All return refund tiktok.csv', null, null, true);

    app(StartImport::class)->handle($file, TiktokReturnImporter::class, ['shop_id' => $shop->id]);

    $return = OrderReturn::query()->where('platform_return_id', 'TTR-1')->firstOrFail();

    expect($return->return_type)->toBe(ReturnType::ReturnAndRefund)
        ->and($return->sub_status)->toBe(ReturnSubStatus::CourierClaimsDelivered)
        ->and($return->lines)->toHaveCount(2)
        // ฿249×1 + ฿234×2 = ฿717.00 — the per-row amounts sum per case.
        ->and($return->refund_amount?->satang)->toBe(71700)
        ->and($return->refunded)->toBeTrue()
        ->and($return->refunded_at?->format('Y-m-d H:i'))->toBe('2026-05-08 09:58')
        ->and($return->buyer_note)->toBe('สีไม่ตรง')
        ->and($return->tracking_number)->toBe('TTRL01');
});

it('maps Request Canceled and Refund rejected to the Platform closure', function () {
    $shop = tiktokShopWithOrder();

    $file = new UploadedFile(writeTiktokReturnCsv([
        [
            'Return Order ID' => 'TTR-2', 'Order ID' => '579xx900', 'Seller SKU' => 'TS-RED-M',
            'Return Type' => 'Return and refund', 'Return Quantity' => '1', 'Return unit price' => '฿249.00',
            'Return Status' => 'Refund rejected', 'Return Sub Status' => 'Request Canceled',
            'Time Requested' => "07/05/2026 20:58:44\t",
        ],
    ]), 'returns.csv', null, null, true);

    app(StartImport::class)->handle($file, TiktokReturnImporter::class, ['shop_id' => $shop->id]);

    $return = OrderReturn::query()->where('platform_return_id', 'TTR-2')->firstOrFail();

    expect($return->sub_status)->toBe(ReturnSubStatus::Closed)
        ->and($return->refunded)->toBeFalse();
});

it('keeps a completed Refund only case terminal with no goods journey', function () {
    $shop = tiktokShopWithOrder();

    $file = new UploadedFile(writeTiktokReturnCsv([
        [
            'Return Order ID' => 'TTR-3', 'Order ID' => '579xx900', 'Seller SKU' => 'TS-RED-M',
            'Return Type' => 'Refund only', 'Return Quantity' => '1', 'Return unit price' => '฿249.00',
            'Return Status' => 'Completed', 'Time Requested' => "07/05/2026 20:58:44\t",
            'Refund Time' => "08/05/2026 16:58:12\t",
        ],
    ]), 'returns.csv', null, null, true);

    app(StartImport::class)->handle($file, TiktokReturnImporter::class, ['shop_id' => $shop->id]);

    $return = OrderReturn::query()->where('platform_return_id', 'TTR-3')->firstOrFail();

    expect($return->return_type)->toBe(ReturnType::RefundOnly)
        ->and($return->sub_status)->toBe(ReturnSubStatus::Closed)
        ->and($return->refunded)->toBeTrue();
});

it('holds a row with an unknown Return Type or Status', function () {
    $shop = tiktokShopWithOrder();

    $file = new UploadedFile(writeTiktokReturnCsv([
        [
            'Return Order ID' => 'TTR-4', 'Order ID' => '579xx900', 'Seller SKU' => 'TS-RED-M',
            'Return Type' => '', 'Return Quantity' => '1', 'Return unit price' => '฿249.00',
            'Return Status' => '', 'Time Requested' => "07/05/2026 20:58:44\t",
        ],
    ]), 'returns.csv', null, null, true);

    $job = app(StartImport::class)->handle($file, TiktokReturnImporter::class, ['shop_id' => $shop->id]);

    expect($job->refresh()->error_rows)->toBe(1)
        ->and(OrderReturn::query()->count())->toBe(0);
});
