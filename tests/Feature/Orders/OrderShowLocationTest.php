<?php

use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChannelSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
});

it('shows the province/city and shipping method cards on the order page', function () {
    $order = app(OrderIngestPipeline::class)->ingest(9501, [
        'id' => 9501, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 50000, 'created_via' => 'checkout',
        'customer_id' => 0,
        'billing' => ['first_name' => 'زهرا', 'last_name' => 'کریمی', 'phone' => '09121234567', 'city' => 'قم', 'state' => 'QHM'],
        'shipping_lines' => [['method_title' => 'پست پیشتاز']],
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00',
        'date_paid' => '2026-07-05T10:05:00', 'meta' => [],
        'line_items' => [['id' => 1, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 1, 'variation_id' => null]],
    ], 'manual');

    $this->actingAs($this->admin)->get("/orders/{$order->id}")->assertOk()
        ->assertSee('استان و شهر')
        ->assertSee('قم')
        ->assertSee('شیوه ارسال')
        ->assertSee('پست پیشتاز');
});
