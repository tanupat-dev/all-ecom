<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\DeleteProductImage;
use App\Actions\Catalog\StoreProductImage;
use App\Actions\Tenants\CreateTenant;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('product-images');

    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('ImgTenant');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─── helpers ──────────────────────────────────────────────────────────────────

/**
 * Creates a real JPEG UploadedFile from an in-memory GD image so GD can
 * decode it back in StoreProductImage. The file is left at the temp path;
 * PHP cleans it up after the test.
 *
 * @param  positive-int  $width
 * @param  positive-int  $height
 */
function makeFakeJpeg(int $width, int $height): UploadedFile
{
    $gd = imagecreatetruecolor($width, $height);

    assert($gd instanceof GdImage);

    $red = imagecolorallocate($gd, 220, 50, 50);

    if ($red === false) {
        throw new RuntimeException('GD failed to allocate colour in test helper.');
    }

    imagefill($gd, 0, 0, $red);

    $tmp = tempnam(sys_get_temp_dir(), 'pimg_').'.jpg';
    imagejpeg($gd, $tmp, 90);
    imagedestroy($gd);

    return new UploadedFile($tmp, 'test.jpg', 'image/jpeg', null, true);
}

function makeFakeProduct(): Product
{
    return app(CreateProduct::class)->handle(
        'เสื้อทดสอบรูป',
        [['master_sku' => 'IMG-'.uniqid(), 'list_price' => Money::fromBaht('100')]],
    );
}

// ─── StoreProductImage ────────────────────────────────────────────────────────

it('stores a non-square image, creates a DB row, and writes the file', function () {
    $product = makeFakeProduct();
    $file = makeFakeJpeg(300, 150); // non-square: 2:1 landscape

    $image = app(StoreProductImage::class)->handle($product, $file);

    // DB row persisted
    expect(ProductImage::query()->where('id', $image->id)->exists())->toBeTrue()
        ->and($image->product_id)->toBe($product->id)
        ->and($image->sort_order)->toBe(0);

    // File on disk
    expect(Storage::disk('product-images')->exists($image->path))->toBeTrue();
});

it('normalises a non-square upload to a 1:1 (square) JPEG', function () {
    $product = makeFakeProduct();
    $file = makeFakeJpeg(400, 200); // 2:1 landscape

    $image = app(StoreProductImage::class)->handle($product, $file);

    $raw = Storage::disk('product-images')->get($image->path);
    assert(is_string($raw));

    $decoded = imagecreatefromstring($raw);
    assert($decoded instanceof GdImage);

    expect(imagesx($decoded))->toBe(imagesy($decoded)); // must be square

    imagedestroy($decoded);
});

it('already-square image stays square', function () {
    $product = makeFakeProduct();
    $file = makeFakeJpeg(200, 200);

    $image = app(StoreProductImage::class)->handle($product, $file);

    $raw = Storage::disk('product-images')->get($image->path);
    assert(is_string($raw));
    $decoded = imagecreatefromstring($raw);
    assert($decoded instanceof GdImage);

    expect(imagesx($decoded))->toBe(imagesy($decoded));

    imagedestroy($decoded);
});

it('assigns monotonically increasing sort_order when multiple images uploaded', function () {
    $product = makeFakeProduct();

    $first = app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));
    $second = app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));
    $third = app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));

    expect($first->sort_order)->toBe(0)
        ->and($second->sort_order)->toBe(1)
        ->and($third->sort_order)->toBe(2);
});

it('stores the image under a tenant-scoped path', function () {
    $product = makeFakeProduct();
    $file = makeFakeJpeg(100, 100);

    $image = app(StoreProductImage::class)->handle($product, $file);

    $tenantId = (string) $product->tenant_id;
    expect($image->path)->toStartWith("tenants/{$tenantId}/product-images/");
});

it('rejects a non-image file with InvalidArgumentException', function () {
    $product = makeFakeProduct();

    $tmp = tempnam(sys_get_temp_dir(), 'notimg_');
    file_put_contents($tmp, 'this is not an image');
    $badFile = new UploadedFile($tmp, 'malicious.exe', 'application/octet-stream', null, true);

    app(StoreProductImage::class)->handle($product, $badFile);
})->throws(InvalidArgumentException::class, 'not a valid image');

it('rejects a text file disguised as a JPEG', function () {
    $product = makeFakeProduct();

    $tmp = tempnam(sys_get_temp_dir(), 'fake_').'.jpg';
    file_put_contents($tmp, '<?php echo "hello"; ?>');
    $badFile = new UploadedFile($tmp, 'fake.jpg', 'image/jpeg', null, true);

    app(StoreProductImage::class)->handle($product, $badFile);
})->throws(InvalidArgumentException::class, 'not a valid image');

it('attaches to a specific Variant when one is provided', function () {
    $product = makeFakeProduct();
    $variant = $product->variants->first();

    assert($variant !== null);

    $image = app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100), $variant);

    expect($image->variant_id)->toBe($variant->id);
});

// ─── ProductImage URL accessor ─────────────────────────────────────────────

it('exposes a public URL through the url accessor', function () {
    $product = makeFakeProduct();
    $image = app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));

    $image->refresh();

    // Split: PHPStan cannot narrow the Expectation type across chained ->not
    // after ->toBeString() on a magic accessor (string|null from its perspective).
    expect($image->url)->toBeString();
    expect($image->url)->not->toBeEmpty();
});

// ─── Product helpers ──────────────────────────────────────────────────────────

it('returns images ordered by sort_order via the Product relation', function () {
    $product = makeFakeProduct();

    app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));
    app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));
    app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));

    $product->load('images');

    $orders = $product->images->pluck('sort_order')->all();
    expect($orders)->toBe([0, 1, 2]);
});

it('returns the first image via primaryImage', function () {
    $product = makeFakeProduct();

    $first = app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));
    app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));

    $primary = $product->primaryImage()->first();

    expect($primary?->id)->toBe($first->id);
});

// ─── DeleteProductImage ────────────────────────────────────────────────────────

it('deletes the file and the DB row', function () {
    $product = makeFakeProduct();
    $image = app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));

    $path = $image->path;
    $id = $image->id;

    app(DeleteProductImage::class)->handle($image);

    expect(Storage::disk('product-images')->exists($path))->toBeFalse()
        ->and(ProductImage::query()->withoutGlobalScopes()->where('id', $id)->exists())->toBeFalse();
});

it('does not throw when the file is already missing on delete', function () {
    $product = makeFakeProduct();
    $image = app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));

    // Manually remove the file from storage before calling delete
    Storage::disk('product-images')->delete($image->path);

    // Should not throw
    app(DeleteProductImage::class)->handle($image);

    expect(ProductImage::query()->withoutGlobalScopes()->where('id', $image->id)->exists())->toBeFalse();
});

// ─── Cross-tenant isolation (ADR 0011) ────────────────────────────────────────

it('passes the cross-tenant isolation harness (product_images)', function () {
    assertTenantIsolation(function (): ProductImage {
        $product = app(CreateProduct::class)->handle(
            'สินค้าแยกเช่า',
            [['master_sku' => 'ISO-'.uniqid(), 'list_price' => Money::fromBaht('100')]],
        );

        return app(StoreProductImage::class)->handle($product, makeFakeJpeg(100, 100));
    });
});

// ─── RBAC — upload & delete gated on product.edit (ADR 0012) ──────────────────

it('allows update (upload/delete actions) when user has product.edit', function () {
    $product = makeFakeProduct();
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    // Assign the default Admin role, which holds all permissions including product.edit.
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('Admin');

    expect($user->can('update', $product))->toBeTrue();
});

it('denies update (upload/delete actions) for user with only product.view', function () {
    $product = makeFakeProduct();
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $viewOnly = Role::findOrCreate('ViewOnly-'.uniqid(), 'web');
    $viewOnly->syncPermissions(['product.view']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($viewOnly);

    expect($user->can('update', $product))->toBeFalse();
});
