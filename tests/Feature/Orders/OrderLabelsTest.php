<?php

use App\Domain\Orders\Models\OrderLabel;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChannelSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
});

function labelsHubOrder(int $id): array
{
    return [
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 50000, 'created_via' => 'checkout',
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 1, 'variation_id' => null]],
    ];
}

it('attaches the seeded wholesale label and a freshly typed label to an order in one submit', function () {
    $order = app(OrderIngestPipeline::class)->ingest(9001, labelsHubOrder(9001), 'manual');
    $wholesale = OrderLabel::firstWhere('slug', 'wholesale');

    $this->actingAs($this->admin)
        ->post(route('orders.labels', $order), [
            'label_ids' => [$wholesale->id],
            'new_label_name' => 'اولویت بالا',
        ])
        ->assertRedirect();

    $order->refresh()->load('labels');
    expect($order->labels->pluck('name')->sort()->values()->all())->toBe(['اولویت بالا', 'سفارش عمده']);
    expect(OrderLabel::where('name', 'اولویت بالا')->exists())->toBeTrue();
});

it('removes a label from an order when it is unchecked and resubmitted', function () {
    $order = app(OrderIngestPipeline::class)->ingest(9002, labelsHubOrder(9002), 'manual');
    $wholesale = OrderLabel::firstWhere('slug', 'wholesale');
    $order->labels()->attach($wholesale->id);

    $this->actingAs($this->admin)
        ->post(route('orders.labels', $order), ['label_ids' => []])
        ->assertRedirect();

    expect($order->refresh()->labels()->count())->toBe(0);
});

it('renders the order show page with label chips and the add-label form', function () {
    $order = app(OrderIngestPipeline::class)->ingest(9004, labelsHubOrder(9004), 'manual');
    $order->labels()->attach(OrderLabel::firstWhere('slug', 'wholesale')->id);

    $this->actingAs($this->admin)->get("/orders/{$order->id}")->assertOk()
        ->assertViewIs('pages.orders.show')
        ->assertViewHas('availableLabels')
        ->assertSee('سفارش عمده');
});

it('blocks partner viewers from changing order labels', function () {
    $order = app(OrderIngestPipeline::class)->ingest(9003, labelsHubOrder(9003), 'manual');
    $partner = User::factory()->create()->assignRole('partner_viewer');

    $this->actingAs($partner)
        ->post(route('orders.labels', $order), ['label_ids' => []])
        ->assertForbidden();
});
