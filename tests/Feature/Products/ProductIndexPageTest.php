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

    // Sort state is now a TableQuery in the URL (?sort=-hub_modified_at), not a
    // pair of `sort`/`dir` view variables.
    $this->actingAs($this->admin)->get('/products')->assertOk()
        ->assertViewIs('pages.products.index')
        ->assertViewHas('query', fn ($query) => $query->sortDir('hub_modified_at') === 'desc');
});

it('sorts products by price ascending and descending', function () {
    ProductMirror::create(['hub_product_id' => 1, 'type' => 'simple', 'name' => 'محصول الف', 'price' => 300000, 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 2, 'type' => 'simple', 'name' => 'محصول ب', 'price' => 100000, 'payload' => []]);

    $this->actingAs($this->admin)->get('/products?sort=price')->assertViewHas(
        'products', fn ($products) => $products->pluck('hub_product_id')->all() === [2, 1],
    );

    // A leading '-' is descending — the whole sort contract, in one character.
    $this->actingAs($this->admin)->get('/products?sort=-price')->assertViewHas(
        'products', fn ($products) => $products->pluck('hub_product_id')->all() === [1, 2],
    );
});

it('keeps the search filter alive while sorting', function () {
    // The reason sort URLs are built by TableQuery and not by hand: a sort click
    // used to be able to silently drop the filter the user had applied.
    ProductMirror::create(['hub_product_id' => 1, 'type' => 'simple', 'name' => 'ماسک الف', 'price' => 300000, 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 2, 'type' => 'simple', 'name' => 'ماسک ب', 'price' => 100000, 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 3, 'type' => 'simple', 'name' => 'دستکش', 'price' => 200000, 'payload' => []]);

    $this->actingAs($this->admin)->get('/products?search=ماسک&sort=price')
        ->assertViewHas('products', fn ($products) => $products->pluck('hub_product_id')->all() === [2, 1])
        ->assertViewHas('query', fn ($query) => str_contains($query->sortUrl('name'), 'search=%D9%85%D8%A7%D8%B3%DA%A9'));
});

it('falls back to the default sort for an unrecognized sort key', function () {
    ProductMirror::create(['hub_product_id' => 1, 'type' => 'simple', 'name' => 'محصول الف', 'payload' => []]);

    $this->actingAs($this->admin)->get('/products?sort=some_unknown_column')->assertOk();
});
