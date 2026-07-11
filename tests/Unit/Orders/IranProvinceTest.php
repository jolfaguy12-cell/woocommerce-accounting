<?php

use App\Domain\Orders\Support\IranProvince;

it('decodes a standard WooCommerce Iran state code', function () {
    expect(IranProvince::resolve('QHM'))->toBe('قم')
        ->and(IranProvince::resolve('thr'))->toBe('تهران');
});

it('passes through already human-readable Persian text', function () {
    expect(IranProvince::resolve('تهران'))->toBe('تهران');
});

it('does not guess a province for an unrecognized numeric or Latin code', function () {
    expect(IranProvince::resolve('607'))->toBeNull()
        ->and(IranProvince::resolve('XYZ'))->toBeNull();
});

it('returns null for empty input', function () {
    expect(IranProvince::resolve(null))->toBeNull()
        ->and(IranProvince::resolve(''))->toBeNull();
});
