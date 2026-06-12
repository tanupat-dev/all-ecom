<?php

use App\Models\Tenant;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('tenancy_test_items', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('label');
    });
    Rls::enable('tenancy_test_items');

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
    Schema::dropIfExists('tenancy_test_items');
});

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $label
 */
class TenancyTestItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'tenancy_test_items';
}

it('runs this suite as a role RLS actually applies to', function () {
    // A superuser or BYPASSRLS role silently bypasses RLS and every proof
    // below would pass without proving anything (ADR 0018).
    $bypassesRls = DB::scalar('select rolsuper or rolbypassrls from pg_roles where rolname = current_user');

    expect($bypassesRls)->toBeFalse();
});

it('creates a Tenant', function () {
    $tenant = Tenant::query()->create(['name' => 'ร้านทดสอบ']);

    expect($tenant->id)->toBeInt()
        ->and($tenant->name)->toBe('ร้านทดสอบ');
});

it('fills tenant_id from the current tenant context on create', function () {
    $tenant = Tenant::query()->create(['name' => 'A']);
    app(TenantContext::class)->set($tenant);

    $item = TenancyTestItem::query()->create(['label' => 'sticker']);

    expect($item->tenant_id)->toBe($tenant->id);
});

it('scopes every query to the current tenant', function () {
    [$a, $b] = createTwoTenantsWithOneItemEach();

    app(TenantContext::class)->set($a);

    expect(TenancyTestItem::query()->pluck('label')->all())->toBe(['a-item']);

    app(TenantContext::class)->set($b);

    expect(TenancyTestItem::query()->pluck('label')->all())->toBe(['b-item']);
});

it('RLS fails closed: with no tenant context the database returns zero rows', function () {
    createTwoTenantsWithOneItemEach();

    expect(DB::table('tenancy_test_items')->count())->toBe(0);
});

it('RLS hides the other tenant even when the query bypasses Eloquent', function () {
    [$a] = createTwoTenantsWithOneItemEach();

    app(TenantContext::class)->set($a);

    expect(DB::table('tenancy_test_items')->pluck('label')->all())->toBe(['a-item']);
});

it('RLS blocks reading, updating and deleting the other tenant\'s rows', function () {
    [$a, $b] = createTwoTenantsWithOneItemEach();

    app(TenantContext::class)->set($b);
    $bItemId = TenancyTestItem::query()->firstOrFail()->id;
    expect($bItemId)->toBeGreaterThan(0);

    app(TenantContext::class)->set($a);

    expect(DB::table('tenancy_test_items')->where('id', $bItemId)->exists())->toBeFalse()
        ->and(DB::table('tenancy_test_items')->where('id', $bItemId)->update(['label' => 'stolen']))->toBe(0)
        ->and(DB::table('tenancy_test_items')->where('id', $bItemId)->delete())->toBe(0);

    app(TenantContext::class)->set($b);

    expect(DB::table('tenancy_test_items')->where('id', $bItemId)->value('label'))->toBe('b-item');
});

it('RLS rejects writing a row for another tenant (WITH CHECK)', function () {
    [$a, $b] = createTwoTenantsWithOneItemEach();

    app(TenantContext::class)->set($a);

    // A savepoint keeps the test's outer transaction usable after the
    // expected policy violation.
    DB::transaction(function () use ($b) {
        DB::table('tenancy_test_items')->insert(['tenant_id' => $b->id, 'label' => 'planted']);
    });
})->throws(QueryException::class, 'row-level security policy');

/**
 * Seeds one item for each of two tenants.
 *
 * @return array{Tenant, Tenant}
 */
function createTwoTenantsWithOneItemEach(): array
{
    $context = app(TenantContext::class);

    $a = Tenant::query()->create(['name' => 'A']);
    $b = Tenant::query()->create(['name' => 'B']);

    $context->set($a);
    TenancyTestItem::query()->create(['label' => 'a-item']);

    $context->set($b);
    TenancyTestItem::query()->create(['label' => 'b-item']);

    $context->forget();

    return [$a, $b];
}
