<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Imports\StartImport;
use App\Actions\Listings\CreateListing;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Imports\LazadaOrderImporter;
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

function lazadaShop(): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $shop = app(CreateShop::class)->handle('lazada1', Platform::Lazada, $location);

    $product = app(CreateProduct::class)->handle('เสื้อยืด', [
        ['master_sku' => 'TS-RED-M', 'name' => 'แดง / M', 'list_price' => Money::fromBaht('199')],
        ['master_sku' => 'TS-RED-L', 'name' => 'แดง / L', 'list_price' => Money::fromBaht('199')],
    ]);
    app(CreateListing::class)->handle($shop, $product);

    return $shop;
}

/**
 * The real export's 77 headers (ref doc/lazada/All order lazada.xlsx) —
 * one row per ITEM (orderItemId) — with synthetic rows only.
 *
 * @param  list<array<string, string>>  $rows  header => value, unset = ''
 */
function writeLazadaXlsx(array $rows): string
{
    $headers = [
        'orderItemId', 'orderType', 'Guarantee', 'deliveryType', 'lazadaId', 'sellerSku', 'lazadaSku',
        'wareHouse', 'createTime', 'updateTime', 'rtsSla', 'ttsSla', 'orderNumber', 'invoiceRequired',
        'invoiceNumber', 'deliveredDate', 'customerName', 'customerEmail', 'nationalRegistrationNumber',
        'shippingName', 'shippingAddress', 'shippingAddress2', 'shippingAddress3', 'shippingAddress4',
        'shippingAddress5', 'shippingPhone', 'shippingPhone2', 'shippingCity', 'shippingPostCode',
        'shippingCountry', 'shippingRegion', 'billingName', 'billingAddr', 'billingAddr2', 'billingAddr3',
        'billingAddr4', 'billingAddr5', 'billingPhone', 'billingPhone2', 'billingCity', 'billingPostCode',
        'billingCountry', 'taxCode', 'branchNumber', 'taxInvoiceRequested', 'payMethod', 'paidPrice',
        'unitPrice', 'sellerDiscountTotal', 'platformDiscountTotal', 'shippingFee', 'walletCredit',
        'itemName', 'variation', 'cdShippingProvider', 'shippingProvider', 'shipmentTypeName',
        'shippingProviderType', 'cdTrackingCode', 'trackingCode', 'trackingUrl', 'shippingProviderFM',
        'trackingCodeFM', 'trackingUrlFM', 'promisedShippingTime', 'premium', 'status',
        'buyerFailedDeliveryReturnInitiator', 'buyerFailedDeliveryReason', 'buyerFailedDeliveryDetail',
        'buyerFailedDeliveryUserName', 'bundleId', 'semiManaged', 'flexibleDeliveryTime',
        'bundleDiscount', 'refundAmount', 'sellerNote',
    ];

    $path = sys_get_temp_dir().'/lazada-import-test-'.uniqid().'.xlsx';
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

it('maps every observed Lazada native status', function () {
    $mapper = app(LazadaOrderImporter::class);

    expect($mapper->map('shipped'))->toBe(OrderStatus::InTransit)
        ->and($mapper->map('confirmed'))->toBe(OrderStatus::Completed)
        ->and($mapper->map('canceled'))->toBe(OrderStatus::Cancelled)
        ->and($mapper->map('Package Returned'))->toBe(OrderStatus::Bounced)
        // A post-delivery buyer return is a Return entity (ADR 0006) —
        // the parent order legitimately stays สำเร็จ.
        ->and($mapper->map('returned'))->toBe(OrderStatus::Completed);
});

it('holds Lost by 3PL — no canonical state fits a lost parcel, so it is deliberately unmapped', function () {
    app(LazadaOrderImporter::class)->map('Lost by 3PL');
})->throws(UnmappedPlatformStatusException::class, 'Lost by 3PL');

it('imports a Lazada export: per-unit rows aggregate, Effective Price nets the seller discount, delivered_date lands in UTC', function () {
    $shop = lazadaShop();

    /** @param array<string, string> $extra */
    $unit = fn (string $order, string $sku, string $status, array $extra = []): array => $extra + [
        'orderNumber' => $order, 'sellerSku' => $sku, 'status' => $status,
        'createTime' => '03 Jun 2026 20:41', 'unitPrice' => '235.00',
        'customerName' => 'น***า',
    ];

    $file = new UploadedFile(writeLazadaXlsx([
        // Two units of M = two rows (Lazada is one row per unit) with a
        // seller-funded discount; the platform subsidy must NOT reduce it.
        $unit('LZ-1', 'TS-RED-M', 'confirmed', ['sellerDiscountTotal' => '-23.50', 'platformDiscountTotal' => '-11.75', 'deliveredDate' => '27 May 2026 13:21', 'trackingCode' => 'TH55001']),
        $unit('LZ-1', 'TS-RED-M', 'confirmed', ['sellerDiscountTotal' => '-23.50', 'platformDiscountTotal' => '-11.75', 'deliveredDate' => '27 May 2026 13:21', 'trackingCode' => 'TH55001']),
        $unit('LZ-2', 'TS-RED-L', 'shipped'),
    ]), 'orders.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, LazadaOrderImporter::class, ['shop_id' => $shop->id]);
    $job->refresh();

    $confirmed = Order::query()->where('platform_order_id', 'LZ-1')->firstOrFail();
    $shipped = Order::query()->where('platform_order_id', 'LZ-2')->firstOrFail();

    expect($job->status)->toBe(ImportJobStatus::Completed)
        ->and($confirmed->status)->toBe(OrderStatus::Completed)
        ->and($confirmed->lines)->toHaveCount(1)
        ->and($confirmed->lines->first()?->qty)->toBe(2)
        // 235.00 − 23.50 = 211.50 each (Effective Price, ADR 0015 satang).
        ->and($confirmed->lines->first()?->unit_price?->satang)->toBe(21150)
        ->and($confirmed->total?->satang)->toBe(42300)
        ->and($confirmed->tracking_number)->toBe('TH55001')
        // Thai wall-clock 27 May 13:21 → UTC 06:21 (Lazada's payout anchor).
        ->and($confirmed->delivered_date?->format('Y-m-d H:i'))->toBe('2026-05-27 06:21')
        ->and($confirmed->completed_date)->toBeNull()
        ->and($shipped->status)->toBe(OrderStatus::InTransit);
});

it('drops the canceled items of a partially-cancelled order and keeps the live ones', function () {
    $shop = lazadaShop();

    $file = new UploadedFile(writeLazadaXlsx([
        ['orderNumber' => 'LZ-3', 'sellerSku' => 'TS-RED-M', 'status' => 'confirmed', 'createTime' => '03 Jun 2026 20:41', 'unitPrice' => '199.00'],
        ['orderNumber' => 'LZ-3', 'sellerSku' => 'TS-RED-L', 'status' => 'canceled', 'createTime' => '03 Jun 2026 20:41', 'unitPrice' => '199.00'],
    ]), 'orders.xlsx', null, null, true);

    app(StartImport::class)->handle($file, LazadaOrderImporter::class, ['shop_id' => $shop->id]);

    $order = Order::query()->where('platform_order_id', 'LZ-3')->firstOrFail();

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->lines)->toHaveCount(1)
        ->and($order->lines->first()?->variant()->firstOrFail()->master_sku)->toBe('TS-RED-M');
});

it('mirrors an all-items-canceled order as ยกเลิก with its lines intact', function () {
    $shop = lazadaShop();

    $file = new UploadedFile(writeLazadaXlsx([
        ['orderNumber' => 'LZ-4', 'sellerSku' => 'TS-RED-M', 'status' => 'canceled', 'createTime' => '03 Jun 2026 20:41', 'unitPrice' => '199.00'],
    ]), 'orders.xlsx', null, null, true);

    app(StartImport::class)->handle($file, LazadaOrderImporter::class, ['shop_id' => $shop->id]);

    $order = Order::query()->where('platform_order_id', 'LZ-4')->firstOrFail();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->lines)->toHaveCount(1);
});
