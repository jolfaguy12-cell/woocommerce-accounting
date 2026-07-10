<?php

use App\Domain\Sync\Models\WebhookEvent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    config(['hub.base_url' => 'https://hub.test/api/v1', 'hub.api_key' => 'k']);
    Http::fake(['hub.test/api/v1/health' => Http::response(['status' => 'ok'])]);
});

it('lets an admin view every tools and settings page', function (string $path) {
    $this->actingAs($this->admin)->get($path)->assertOk();
})->with([
    '/tools/backup',
    '/tools/system-status',
    '/tools/system-logs',
    '/tools/alerts',
    '/setting',
    '/setting/report-settings',
    '/setting/role-managment',
    '/setting/api-webhook-managment',
]);

it('blocks non-admins from tools and settings pages', function (string $path) {
    $this->actingAs($this->accountant)->get($path)->assertForbidden();
})->with([
    '/tools/backup',
    '/tools/system-status',
    '/tools/system-logs',
    '/tools/alerts',
    '/setting',
    '/setting/report-settings',
    '/setting/role-managment',
    '/setting/api-webhook-managment',
]);

it('retries failed webhook events from the system logs page', function () {
    Http::fake([
        'hub.test/api/v1/health' => Http::response(['status' => 'ok']),
        'hub.test/api/v1/orders/*' => Http::response(['id' => 1, 'status' => 'completed', 'total' => 0, 'line_items' => []]),
    ]);

    $event = WebhookEvent::create([
        'event_uuid' => 'dead-1', 'event_type' => 'order.upserted',
        'payload' => ['order_id' => 9999], 'status' => 'dead', 'attempts' => 3,
        'last_error' => 'timeout',
    ]);

    $this->actingAs($this->admin)->post('/tools/system-logs/retry')
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($event->refresh()->status)->not->toBe('dead');
});
