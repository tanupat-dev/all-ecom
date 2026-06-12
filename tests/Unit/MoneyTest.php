<?php

use App\Support\Money;

it('holds an integer amount of satang', function () {
    expect(Money::fromSatang(12950)->satang)->toBe(12950);
});

it('parses a baht string exactly into satang', function (string $baht, int $satang) {
    expect(Money::fromBaht($baht)->satang)->toBe($satang);
})->with([
    ['129.50', 12950],
    ['129.5', 12950],
    ['129', 12900],
    ['0.01', 1],
    ['-45.25', -4525],
    ['0', 0],
]);

it('rejects a baht string it cannot represent exactly', function (string $baht) {
    Money::fromBaht($baht);
})->throws(InvalidArgumentException::class)->with([
    '129.505',   // sub-satang precision
    '12,950',    // thousands separator
    'abc',
    '',
    '1.2e3',     // scientific notation
    '.50',       // missing integer part
]);

it('adds exactly', function () {
    expect(Money::fromSatang(10050)->add(Money::fromSatang(25))->satang)->toBe(10075);
});

it('subtracts exactly and may go negative', function () {
    expect(Money::fromSatang(100)->subtract(Money::fromSatang(250))->satang)->toBe(-150);
});

it('multiplies by an integer quantity exactly', function () {
    expect(Money::fromSatang(12950)->multiply(3)->satang)->toBe(38850);
});

it('negates (POS Return / refund lines)', function () {
    expect(Money::fromSatang(500)->negate()->satang)->toBe(-500);
});

it('is immutable — arithmetic returns a new instance', function () {
    $a = Money::fromSatang(100);
    $a->add(Money::fromSatang(1));

    expect($a->satang)->toBe(100);
});

it('compares by amount', function () {
    expect(Money::fromSatang(100)->equals(Money::fromSatang(100)))->toBeTrue()
        ->and(Money::fromSatang(100)->equals(Money::fromSatang(101)))->toBeFalse();
});

it('knows zero and negative', function () {
    expect(Money::fromSatang(0)->isZero())->toBeTrue()
        ->and(Money::fromSatang(-1)->isNegative())->toBeTrue()
        ->and(Money::fromSatang(1)->isNegative())->toBeFalse();
});

it('formats back to a canonical baht string', function (int $satang, string $baht) {
    expect(Money::fromSatang($satang)->toBaht())->toBe($baht);
})->with([
    [12950, '129.50'],
    [12900, '129.00'],
    [1, '0.01'],
    [-4525, '-45.25'],
    [0, '0.00'],
]);
