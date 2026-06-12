<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Tenants\CreateTenant;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('creates a Product with its Variants', function () {
    $product = app(CreateProduct::class)->handle('เสื้อยืด', [
        ['master_sku' => 'TS-RED-M', 'name' => 'แดง / M', 'list_price' => Money::fromBaht('199')],
        ['master_sku' => 'TS-RED-L', 'name' => 'แดง / L', 'list_price' => Money::fromBaht('199')],
    ]);

    expect($product->variants)->toHaveCount(2)
        ->and($product->variants->first()?->master_sku)->toBe('TS-RED-M')
        ->and($product->variants->first()?->list_price?->satang)->toBe(19900);
});

it('creates exactly one default Variant for a Product with no options', function () {
    $product = app(CreateProduct::class)->handle('แก้วน้ำ', [
        ['master_sku' => 'CUP-1', 'list_price' => Money::fromBaht('59.50')],
    ]);

    expect($product->variants)->toHaveCount(1)
        ->and($product->variants->first()?->name)->toBeNull()
        ->and($product->variants->first()?->list_price?->satang)->toBe(5950);
});

it('refuses a Product with no Variant at all', function () {
    app(CreateProduct::class)->handle('สินค้าเปล่า', []);
})->throws(InvalidArgumentException::class, 'at least one Variant');

it('rejects a duplicate Master SKU within the tenant', function () {
    app(CreateProduct::class)->handle('สินค้า 1', [
        ['master_sku' => 'SKU-1', 'list_price' => Money::fromBaht('100')],
    ]);

    app(CreateProduct::class)->handle('สินค้า 2', [
        ['master_sku' => 'SKU-1', 'list_price' => Money::fromBaht('200')],
    ]);
})->throws(QueryException::class, 'variants_tenant_id_master_sku_unique');

it('allows the same Master SKU in another tenant', function () {
    app(CreateProduct::class)->handle('สินค้า 1', [
        ['master_sku' => 'SKU-1', 'list_price' => Money::fromBaht('100')],
    ]);

    $other = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($other);

    $product = app(CreateProduct::class)->handle('สินค้าอีกร้าน', [
        ['master_sku' => 'SKU-1', 'list_price' => Money::fromBaht('100')],
    ]);

    expect($product->variants->first()?->master_sku)->toBe('SKU-1');
});

it('rejects a duplicate barcode within the tenant', function () {
    app(CreateProduct::class)->handle('สินค้า 1', [
        ['master_sku' => 'SKU-1', 'barcode' => '885000111', 'list_price' => Money::fromBaht('100')],
    ]);

    app(CreateProduct::class)->handle('สินค้า 2', [
        ['master_sku' => 'SKU-2', 'barcode' => '885000111', 'list_price' => Money::fromBaht('100')],
    ]);
})->throws(QueryException::class, 'variants_tenant_barcode_unique');

it('creates a product through the panel, entering the price in baht', function () {
    actingAs(User::factory()->create()->assignRole('Admin'));

    Livewire\Livewire::test(App\Filament\Resources\Products\Pages\CreateProduct::class)
        ->fillForm([
            'name' => 'เสื้อยืด',
            'variants' => [
                ['master_sku' => 'TS-1', 'name' => null, 'barcode' => null, 'list_price' => '199.50'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $variant = Variant::query()->where('master_sku', 'TS-1')->firstOrFail();

    expect($variant->list_price?->satang)->toBe(19950)
        ->and($variant->tenant_id)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Listing master fields (ADR 0019, Issue #46)
// ─────────────────────────────────────────────────────────────────────────────

it('stores channel-agnostic Product listing fields via the Action', function () {
    $product = app(CreateProduct::class)->handle(
        name: 'เสื้อยืด',
        variants: [['master_sku' => 'TS-LISTING-1', 'list_price' => Money::fromBaht('199')]],
        meta: [
            'english_name' => 'T-Shirt',
            'description' => 'คอกลม ผ้าคอตตอน 100%',
            'brand' => 'FashionBrand',
        ],
    );

    expect($product->english_name)->toBe('T-Shirt')
        ->and($product->description)->toBe('คอกลม ผ้าคอตตอน 100%')
        ->and($product->brand)->toBe('FashionBrand');
});

it('stores package dimensions on Variant as integers in grams/mm via the Action', function () {
    $product = app(CreateProduct::class)->handle(
        name: 'กระเป๋า',
        variants: [[
            'master_sku' => 'BAG-PKG-1',
            'list_price' => Money::fromBaht('590'),
            'package_weight_g' => 350,
            'package_width_mm' => 200,
            'package_length_mm' => 300,
            'package_height_mm' => 80,
        ]],
    );

    $variant = $product->variants->firstOrFail();

    expect($variant->package_weight_g)->toBe(350)
        ->and($variant->package_width_mm)->toBe(200)
        ->and($variant->package_length_mm)->toBe(300)
        ->and($variant->package_height_mm)->toBe(80);
});

it('allows all listing / dimension fields to be null', function () {
    $product = app(CreateProduct::class)->handle(
        name: 'สินค้าทดสอบ',
        variants: [['master_sku' => 'NULL-TEST-1', 'list_price' => Money::fromBaht('99')]],
    );

    $variant = $product->variants->firstOrFail();

    expect($product->english_name)->toBeNull()
        ->and($product->description)->toBeNull()
        ->and($product->brand)->toBeNull()
        ->and($variant->package_weight_g)->toBeNull()
        ->and($variant->package_width_mm)->toBeNull()
        ->and($variant->package_length_mm)->toBeNull()
        ->and($variant->package_height_mm)->toBeNull();
});

it('creates a product with listing fields through the panel form', function () {
    actingAs(User::factory()->create()->assignRole('Admin'));

    Livewire\Livewire::test(App\Filament\Resources\Products\Pages\CreateProduct::class)
        ->fillForm([
            'name' => 'กระโปรง',
            'english_name' => 'Skirt',
            'description' => 'กระโปรงผ้าชีฟอง',
            'brand' => 'StyleCo',
            'variants' => [[
                'master_sku' => 'SKIRT-PANEL-1',
                'name' => null,
                'barcode' => null,
                'list_price' => '299',
                'package_weight_g' => '250',
                'package_width_mm' => '150',
                'package_length_mm' => '200',
                'package_height_mm' => '30',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('name', 'กระโปรง')->firstOrFail();
    $variant = $product->variants->firstOrFail();

    expect($product->english_name)->toBe('Skirt')
        ->and($product->description)->toBe('กระโปรงผ้าชีฟอง')
        ->and($product->brand)->toBe('StyleCo')
        ->and($variant->package_weight_g)->toBe(250)
        ->and($variant->package_width_mm)->toBe(150)
        ->and($variant->package_length_mm)->toBe(200)
        ->and($variant->package_height_mm)->toBe(30);
});

it('updates listing fields on an existing Product through the panel', function () {
    actingAs(User::factory()->create()->assignRole('Admin'));

    $product = app(CreateProduct::class)->handle(
        name: 'เสื้อแจ็คเก็ต',
        variants: [['master_sku' => 'JKT-EDIT-1', 'list_price' => Money::fromBaht('890')]],
    );

    $variant = $product->variants->firstOrFail();

    Livewire\Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->fillForm([
            'english_name' => 'Jacket',
            'description' => 'เสื้อแจ็คเก็ตกันหนาว',
            'brand' => 'WarmBrand',
            'variants' => [[
                'id' => $variant->getKey(),
                'master_sku' => 'JKT-EDIT-1',
                'list_price' => '890',
                'package_weight_g' => '500',
                'package_width_mm' => '300',
                'package_length_mm' => '400',
                'package_height_mm' => '50',
            ]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $product->refresh();
    // Re-query by SKU — the Repeater may recreate the record with a new id.
    $savedVariant = Variant::query()->where('master_sku', 'JKT-EDIT-1')->firstOrFail();

    expect($product->english_name)->toBe('Jacket')
        ->and($product->brand)->toBe('WarmBrand')
        ->and($savedVariant->package_weight_g)->toBe(500)
        ->and($savedVariant->package_height_mm)->toBe(50);
});

it('passes the cross-tenant isolation harness (products)', function () {
    assertTenantIsolation(fn (): Product => Product::query()->create(['name' => 'สินค้า']));
});

it('passes the cross-tenant isolation harness (variants)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): Variant {
        $sequence++;

        return app(CreateProduct::class)
            ->handle('สินค้า', [['master_sku' => "HARNESS-{$sequence}", 'list_price' => Money::fromBaht('100')]])
            ->variants->firstOrFail();
    });
});
