<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Tenants\CreateTenant;
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
