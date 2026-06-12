<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\DefineBundle;
use App\Actions\Imports\StartImport;
use App\Actions\Listings\CreateListing;
use App\Actions\Listings\ResolvePlatformSku;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Shops\CreateShop;
use App\Actions\Stock\AppendStockMovement;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ImportJobStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\StockAction;
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

/**
 * Stock-reconcile coverage (#31) — hookVariant()/pools() come from
 * OrderStockHooksTest. importShop() listings map the catalog SKUs, so a
 * dedicated stocked Variant is listed here per test.
 */
function stockedListedVariant(Shop $shop, string $sku, int $onHand): Variant
{
    $variant = hookVariant($sku, $onHand);
    app(CreateListing::class)->handle($shop, $variant->product()->firstOrFail());

    return $variant;
}

function normalizedRow(string $orderId, OrderStatus $status, Variant $variant, int $qty = 2): NormalizedOrder
{
    return new NormalizedOrder(
        platformOrderId: $orderId,
        status: $status,
        lines: [['variant' => $variant, 'qty' => $qty, 'unit_price' => Money::fromBaht('159')]],
    );
}

it('RESERVEs the lines of an order imported at รอแพ็ค', function () {
    $shop = importShop();
    $a = stockedListedVariant($shop, 'MK-1', 10);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3001', OrderStatus::AwaitingPack, $a));

    expect(pools($a))->toBe([10, 2]);
});

it('SHIPs an order first seen already in transit without touching Reserved — it never reserved here', function () {
    $shop = importShop();
    $a = stockedListedVariant($shop, 'MK-1', 10);
    // Someone else's reservation must survive the SHIP (order-aware ledger).
    app(AppendStockMovement::class)->handle($a, Location::query()->firstOrFail(), StockAction::Reserve, 3);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3002', OrderStatus::InTransit, $a));

    expect(pools($a))->toBe([8, 3]);
});

it('releases its reservation when a re-import moves รอแพ็ค to shipped', function () {
    $shop = importShop();
    $a = stockedListedVariant($shop, 'MK-1', 10);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3003', OrderStatus::AwaitingPack, $a));
    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3003', OrderStatus::InTransit, $a));

    expect(pools($a))->toBe([8, 0]);
});

it('RELEASEs on a re-import showing ยกเลิก — the import-driven Oversell resolution', function () {
    $shop = importShop();
    $a = stockedListedVariant($shop, 'MK-1', 10);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3004', OrderStatus::AwaitingPack, $a));
    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3004', OrderStatus::Cancelled, $a));

    expect(pools($a))->toBe([10, 0]);
});

it('reserves past On-Hand instead of blocking — negative Available is the Oversell signal', function () {
    $shop = importShop();
    $a = stockedListedVariant($shop, 'MK-1', 1);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3005', OrderStatus::AwaitingPack, $a, qty: 3));

    expect(pools($a))->toBe([1, 3]);
});

it('compensates Reserved when a re-imported snapshot changed the lines of a รอแพ็ค order', function () {
    $shop = importShop();
    $a = stockedListedVariant($shop, 'MK-1', 10);
    $b = stockedListedVariant($shop, 'MK-2', 10);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3006', OrderStatus::AwaitingPack, $a, qty: 2));
    // The buyer dropped a unit of A and the platform added B (partial
    // cancel + re-import): +1 RESERVE B, -1 RELEASE A.
    app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-3006',
        status: OrderStatus::AwaitingPack,
        lines: [
            ['variant' => $a, 'qty' => 1, 'unit_price' => Money::fromBaht('159')],
            ['variant' => $b, 'qty' => 1, 'unit_price' => Money::fromBaht('159')],
        ],
    ));

    expect(pools($a))->toBe([10, 1])
        ->and(pools($b))->toBe([10, 1]);
});

it('moves no stock for a post-pack line change — the goods already left', function () {
    $shop = importShop();
    $a = stockedListedVariant($shop, 'MK-1', 10);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3007', OrderStatus::InTransit, $a, qty: 2));
    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3007', OrderStatus::Delivered, $a, qty: 1));

    // The mirror reflects the platform's line truth, the ledger is untouched.
    expect(pools($a))->toBe([8, 0])
        ->and(Order::query()->where('platform_order_id', 'SP-3007')->firstOrFail()->lines->first()?->qty)->toBe(1);
});

it('expands an imported bundle line into component movements', function () {
    $shop = importShop();
    $soap = hookVariant('SOAP', 10);
    $bundle = stockedListedVariant($shop, 'SET-1', 0);
    app(DefineBundle::class)->handle($bundle, [[$soap, 2]]);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3008', OrderStatus::AwaitingPack, $bundle, qty: 3));

    expect(pools($soap))->toBe([10, 6])
        ->and(pools($bundle))->toBe([0, 0]);
});

it('RESERVEs the appended lines when a chunk-split รอแพ็ค order merges', function () {
    $shop = importShop();
    $a = stockedListedVariant($shop, 'MK-1', 10);
    $b = stockedListedVariant($shop, 'MK-2', 10);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3009', OrderStatus::AwaitingPack, $a, qty: 1));
    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3009', OrderStatus::AwaitingPack, $b, qty: 2), mergeLines: true);

    expect(pools($a))->toBe([10, 1])
        ->and(pools($b))->toBe([10, 2]);
});

it('moves nothing for a รอชำระ order until it reaches รอแพ็ค', function () {
    $shop = importShop();
    $a = stockedListedVariant($shop, 'MK-1', 10);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3010', OrderStatus::PendingPayment, $a));
    expect(pools($a))->toBe([10, 0]);

    app(ImportMarketplaceOrder::class)->handle($shop, normalizedRow('SP-3010', OrderStatus::AwaitingPack, $a));
    expect(pools($a))->toBe([10, 2]);
});

it('honours an explicit exact line_total when a platform export only gives subtotals', function () {
    $shop = importShop();

    // TikTok-style: 6 units, subtotal-after-seller-discount = ฿223.00 —
    // not evenly divisible, so the platform's exact total wins and the
    // unit price is the floored per-unit figure (ADR 0015: no invented
    // satang).
    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-1004',
        status: OrderStatus::AwaitingPack,
        lines: [[
            'variant' => variantBySku('TS-RED-M'), 'qty' => 6,
            'unit_price' => Money::fromSatang(3716),
            'line_total' => Money::fromSatang(22300),
        ]],
    ));

    expect($order->lines->first()?->line_total?->satang)->toBe(22300)
        ->and($order->total?->satang)->toBe(22300);
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
