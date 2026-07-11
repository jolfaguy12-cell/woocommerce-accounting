<?php

use App\Domain\Products\Models\ProductMirror;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('admin');
});

it('renders the products list with sortable column headers', function () {
    ProductMirror::create(['hub_product_id' => 1, 'type' => 'simple', 'name' => 'محصول الف', 'price' => 100000, 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 2, 'type' => 'simple', 'name' => 'محصول ب', 'price' => 50000, 'payload' => []]);

    $this->actingAs($this->admin)->get('/products')->assertOk()
        ->assertViewIs('pages.products.index')
        ->assertViewHas('sort', 'hub_modified_at')
        ->assertViewHas('dir', 'desc');
});

it('sorts products by price ascending and descending', function () {
    ProductMirror::create(['hub_product_id' => 1, 'type' => 'simple', 'name' => 'محصول الف', 'price' => 300000, 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 2, 'type' => 'simple', 'name' => 'محصول ب', 'price' => 100000, 'payload' => []]);

    $this->actingAs($this->admin)->get('/products?sort=price&dir=asc')->assertViewHas(
        'products', fn ($products) => $products->pluck('hub_product_id')->all() === [2, 1],
    );

    $this->actingAs($this->admin)->get('/products?sort=price&dir=desc')->assertViewHas(
        'products', fn ($products) => $products->pluck('hub_product_id')->all() === [1, 2],
    );
});

it('falls back to the default sort for an unrecognized sort key', function () {
    ProductMirror::create(['hub_product_id' => 1, 'type' => 'simple', 'name' => 'محصول الف', 'payload' => []]);

    $this->actingAs($this->admin)->get('/products?sort=some_unknown_column')->assertOk();
});
