<?php

use App\Models\Tenant;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
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
    $context = app(TenantContext::class);
    $a = Tenant::query()->create(['name' => 'A']);
    $b = Tenant::query()->create(['name' => 'B']);

    $context->set($a);
    TenancyTestItem::query()->create(['label' => 'a-item']);

    $context->set($b);
    TenancyTestItem::query()->create(['label' => 'b-item']);

    $context->set($a);
    expect(TenancyTestItem::query()->pluck('label')->all())->toBe(['a-item']);

    $context->set($b);
    expect(TenancyTestItem::query()->pluck('label')->all())->toBe(['b-item']);
});

// Scope fail-closed, raw-query blocking, and WITH CHECK are proven by the
// shared harness every tenant-scoped table must call (Issue #7).
it('passes the cross-tenant isolation harness', function () {
    assertTenantIsolation(fn (): TenancyTestItem => TenancyTestItem::query()->create(['label' => 'harness-item']));
});
