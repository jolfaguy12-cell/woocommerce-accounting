<?php

use App\Helpers\MenuHelper;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
});

it('blocks non-admins from the component showcase', function () {
    $this->actingAs($this->accountant)->get('/components')->assertForbidden();
    $this->actingAs($this->accountant)->get('/components/cards')->assertForbidden();
});

it('lets an admin open the component overview', function () {
    $this->actingAs($this->admin)->get('/components')->assertOk()
        ->assertViewIs('pages.components.overview')
        ->assertSee('کامپوننت‌ها');
});

it('renders every registered category page for an admin', function () {
    foreach (array_keys(config('showcase.categories')) as $category) {
        $this->actingAs($this->admin)->get("/components/{$category}")->assertOk()
            ->assertViewIs('pages.components.category');
    }
});

it('returns 404 for an unknown category', function () {
    $this->actingAs($this->admin)->get('/components/does-not-exist')->assertNotFound();
});

it('gives every registered component a unique, well-formed, stable id', function () {
    $components = collect(config('showcase.components'));
    $ids = $components->pluck('id');

    expect($ids->count())->toBe($ids->unique()->count()); // no duplicates

    $categories = array_keys(config('showcase.categories'));
    foreach ($components as $c) {
        expect($c)->toHaveKeys(['id', 'category', 'name', 'component', 'source', 'description', 'variants']);
        expect($categories)->toContain($c['category']);
        // id shape: "<prefix>-NN" with a zero-padded number (position-independent)
        expect($c['id'])->toMatch('/^[a-z]+-\d{2}$/');
        // every component source file actually exists
        expect(file_exists(base_path($c['source'])) || str_contains($c['source'], 'app.js'))->toBeTrue("missing source: {$c['source']}");
    }
});

it('numbers ids independently and contiguously within each category', function () {
    collect(config('showcase.components'))
        ->groupBy('category')
        ->each(function ($items) {
            $numbers = $items->map(fn ($c) => (int) Str::afterLast($c['id'], '-'))->sort()->values();
            expect($numbers->first())->toBe(1); // starts at 01
            expect($numbers->toArray())->toBe(range(1, $numbers->count())); // 1..N, no gaps/dupes
        });
});

it('points every showcase menu link at a valid route', function () {
    $this->actingAs($this->admin);

    $group = collect(MenuHelper::getMenuGroups())->firstWhere('title', 'کامپوننت‌ها');
    expect($group)->not->toBeNull();

    $subItems = $group['items'][0]['subItems'];
    // overview + one link per registered category
    expect(count($subItems))->toBe(count(config('showcase.categories')) + 1);

    foreach ($subItems as $item) {
        $this->get($item['path'])->assertOk();
    }
});

it('hides the showcase menu group from non-admins', function () {
    $this->actingAs($this->accountant);

    $titles = collect(MenuHelper::getMenuGroups())->pluck('title');
    expect($titles)->not->toContain('کامپوننت‌ها');
});
