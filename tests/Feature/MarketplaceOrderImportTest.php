<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Imports\StartImport;
use App\Actions\Listings\CreateListing;
use App\Actions\Listings\ResolvePlatformSku;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Imports\Importer;
use App\Imports\NormalizedOrder;
use App\Imports\PlatformStatusMapper;
use App\Imports\RowImportException;
use App\Imports\UnmappedPlatformStatusException;
use App\Listings\UnresolvedPlatformSkuException;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Models\Variant;
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
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function importShop(): Shop
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

function variantBySku(string $masterSku): Variant
{
    return Variant::query()->where('master_sku', $masterSku)->firstOrFail();
}

/**
 * A minimal platform importer exercising the real contract end-to-end:
 * fail-loud status mapping (PlatformStatusMapper) + fail-loud SKU
 * resolution (#29) per row, then group-by-order upsert through the core —
 * exactly the shape the Shopee/Lazada/TikTok importers (#32–#34) take.
 */
class FakePlatformOrderImporter implements Importer, PlatformStatusMapper
{
    public static ?int $shopId = null;

    /** @var array<string, true> */
    private array $upsertedOrders = [];

    private const STATUS_MAP = [
        'to_ship' => OrderStatus::AwaitingPack,
        'completed' => OrderStatus::Completed,
    ];

    public function map(string $nativeStatus): OrderStatus
    {
        return self::STATUS_MAP[$nativeStatus]
            ?? throw new UnmappedPlatformStatusException("ระบบไม่รองรับ — unmapped status [{$nativeStatus}]");
    }

    public function mapRow(array $row, int $rowNumber): array
    {
        $shop = Shop::query()->findOrFail(self::$shopId);

        $sku = is_scalar($row['platform_sku'] ?? null) ? trim((string) $row['platform_sku']) : '';

        try {
            $variant = app(ResolvePlatformSku::class)->handle($shop, $sku);
        } catch (UnresolvedPlatformSkuException $e) {
            throw new RowImportException($e->getMessage());
        }

        return [
            'order_id' => is_scalar($row['order_id'] ?? null) ? trim((string) $row['order_id']) : '',
            'status' => $this->map(is_scalar($row['status'] ?? null) ? trim((string) $row['status']) : ''),
            'variant' => $variant,
            'qty' => is_numeric($row['qty'] ?? null) ? (int) $row['qty'] : 0,
            'unit_price' => Money::fromBaht(is_scalar($row['unit_price'] ?? null) ? (string) $row['unit_price'] : '0'),
        ];
    }

    public function upsertChunk(array $chunk): void
    {
        $shop = Shop::query()->findOrFail(self::$shopId);
        $byOrder = [];

        foreach ($chunk as $row) {
            $orderId = $row['order_id'];
            $status = $row['status'];
            $variant = $row['variant'];
            $qty = $row['qty'];
            $price = $row['unit_price'];

            if (! is_string($orderId) || ! $status instanceof OrderStatus || ! $variant instanceof Variant || ! is_int($qty) || ! $price instanceof Money) {
                throw new LogicException('mapRow shape drifted.');
            }

            $byOrder[$orderId][] = ['status' => $status, 'variant' => $variant, 'qty' => $qty, 'unit_price' => $price];
        }

        foreach ($byOrder as $orderId => $rows) {
            app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
                platformOrderId: $orderId,
                status: $rows[0]['status'],
                lines: array_map(static fn (array $r): array => [
                    'variant' => $r['variant'], 'qty' => $r['qty'], 'unit_price' => $r['unit_price'],
                ], $rows),
            ), mergeLines: isset($this->upsertedOrders[$orderId]));

            $this->upsertedOrders[$orderId] = true;
        }
    }
}

/**
 * @param  list<list<string>>  $rows
 */
function writeOrderXlsx(array $rows): string
{
    $path = sys_get_temp_dir().'/order-import-test-'.uniqid().'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['order_id', 'status', 'platform_sku', 'qty', 'unit_price']));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

it('runs end-to-end through the central pipeline, holding unmapped rows while good orders land', function () {
    Storage::fake('local');
    actingAs(User::factory()->create());
    $shop = importShop();
    FakePlatformOrderImporter::$shopId = $shop->id;

    $file = new UploadedFile(writeOrderXlsx([
        ['SP-9001', 'to_ship', 'TS-RED-M', '2', '159'],
        ['SP-9001', 'to_ship', 'TS-RED-L', '1', '159'],
        ['SP-9002', 'pending_arbitration', 'TS-RED-M', '1', '159'],
        ['SP-9003', 'to_ship', 'NOT-IN-CATALOG', '1', '99'],
        ['SP-9004', 'completed', 'TS-RED-L', '1', '199'],
    ]), 'orders.xlsx', null, null, true);

    $job = app(StartImport::class)->handle($file, FakePlatformOrderImporter::class);
    $job->refresh();

    expect($job->status)->toBe(ImportJobStatus::CompletedWithErrors)
        ->and($job->error_rows)->toBe(2)
        ->and(collect($job->errors)->pluck('message')->implode(' '))->toContain('pending_arbitration', 'NOT-IN-CATALOG')
        ->and(Order::query()->count())->toBe(2)
        ->and(Order::query()->where('platform_order_id', 'SP-9001')->firstOrFail()->lines)->toHaveCount(2)
        ->and(Order::query()->where('platform_order_id', 'SP-9002')->exists())->toBeFalse()
        ->and(Order::query()->where('platform_order_id', 'SP-9004')->firstOrFail()->total?->satang)->toBe(19900);
});

it('re-imports the same platform order id as an update, never a duplicate', function () {
    $shop = importShop();
    $row = fn (OrderStatus $status, ?DateTimeImmutable $paidDate = null): NormalizedOrder => new NormalizedOrder(
        platformOrderId: 'SP-1001',
        status: $status,
        lines: [['variant' => variantBySku('TS-RED-M'), 'qty' => 2, 'unit_price' => Money::fromBaht('159')]],
        milestones: $paidDate !== null ? ['paid_date' => $paidDate] : [],
    );

    app(ImportMarketplaceOrder::class)->handle($shop, $row(OrderStatus::AwaitingPack, new DateTimeImmutable('2026-06-01 10:05:00')));
    $order = app(ImportMarketplaceOrder::class)->handle($shop, $row(OrderStatus::InTransit));

    expect(Order::query()->count())->toBe(1)
        ->and($order->status)->toBe(OrderStatus::InTransit)
        // The later snapshot omitted paid_date — never null-overwritten (ADR 0004).
        ->and($order->paid_date?->format('H:i'))->toBe('10:05')
        ->and($order->lines)->toHaveCount(1);
});

it('aggregates rows of the same SKU and price into one Order Line', function () {
    $shop = importShop();

    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-1002',
        status: OrderStatus::AwaitingPack,
        lines: [
            ['variant' => variantBySku('TS-RED-M'), 'qty' => 1, 'unit_price' => Money::fromBaht('159')],
            ['variant' => variantBySku('TS-RED-M'), 'qty' => 2, 'unit_price' => Money::fromBaht('159')],
            // A flash-sale unit at another price stays its own line (ADR 0015).
            ['variant' => variantBySku('TS-RED-M'), 'qty' => 1, 'unit_price' => Money::fromBaht('99')],
        ],
    ));

    expect($order->lines)->toHaveCount(2)
        ->and($order->lines->firstWhere('qty', 3)?->line_total?->satang)->toBe(47700)
        ->and($order->total?->satang)->toBe(57600);
});

it('merges lines for an order split across two pipeline chunks instead of replacing', function () {
    $shop = importShop();
    $part = fn (string $sku, bool $merge): Order => app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-1003',
        status: OrderStatus::AwaitingPack,
        lines: [['variant' => variantBySku($sku), 'qty' => 1, 'unit_price' => Money::fromBaht('159')]],
    ), mergeLines: $merge);

    $part('TS-RED-M', false);
    $order = $part('TS-RED-L', true);

    expect($order->lines)->toHaveCount(2)
        ->and($order->total?->satang)->toBe(31800);
});

it('refuses to mirror an order into a non-marketplace Shop', function () {
    importShop();
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $pos = app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, $location);

    app(ImportMarketplaceOrder::class)->handle($pos, new NormalizedOrder(
        platformOrderId: 'X-1',
        status: OrderStatus::Completed,
        lines: [['variant' => variantBySku('TS-RED-M'), 'qty' => 1, 'unit_price' => Money::fromBaht('159')]],
    ));
})->throws(LogicException::class, 'marketplace');

it('imports a new marketplace Order as a read-only mirror with lines, milestones, and total', function () {
    $shop = importShop();

    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-1001',
        status: OrderStatus::AwaitingPack,
        lines: [
            ['variant' => variantBySku('TS-RED-M'), 'qty' => 2, 'unit_price' => Money::fromBaht('159')],
            ['variant' => variantBySku('TS-RED-L'), 'qty' => 1, 'unit_price' => Money::fromBaht('159')],
        ],
        milestones: ['created_date' => new DateTimeImmutable('2026-06-01 10:00:00'), 'paid_date' => new DateTimeImmutable('2026-06-01 10:05:00')],
    ));

    expect($order->platform_order_id)->toBe('SP-1001')
        ->and($order->status)->toBe(OrderStatus::AwaitingPack)
        ->and($order->lines)->toHaveCount(2)
        ->and($order->total?->satang)->toBe(47700)
        ->and($order->paid_date?->format('H:i'))->toBe('10:05');
});
