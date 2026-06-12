<?php

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('money_cast_test_models', function ($table) {
        $table->id();
        $table->bigInteger('amount')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('money_cast_test_models');
});

class MoneyCastTestModel extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['amount' => MoneyCast::class];
}

it('round-trips Money through an integer column', function () {
    $model = MoneyCastTestModel::query()->create(['amount' => Money::fromSatang(12950)]);

    $fresh = $model->fresh();

    expect($fresh->amount)->toBeInstanceOf(Money::class)
        ->and($fresh->amount->satang)->toBe(12950);
});

it('stores the raw integer satang in the database', function () {
    MoneyCastTestModel::query()->create(['amount' => Money::fromSatang(-4525)]);

    $this->assertDatabaseHas('money_cast_test_models', ['amount' => -4525]);
});

it('casts a null column to null', function () {
    $model = MoneyCastTestModel::query()->create(['amount' => null]);

    expect($model->fresh()->amount)->toBeNull();
});

it('rejects setting anything that is not Money', function () {
    MoneyCastTestModel::query()->create(['amount' => 129.50]);
})->throws(InvalidArgumentException::class);
