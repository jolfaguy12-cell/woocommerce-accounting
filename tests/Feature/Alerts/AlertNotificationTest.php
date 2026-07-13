<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Alerts\Models\AlertDelivery;
use App\Domain\Alerts\Services\AlertDispatcher;
use App\Models\User;
use Database\Seeders\AlertTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, AlertTypeSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->partner = User::factory()->create()->assignRole('partner_viewer');
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
});

function raiseOverdueAlert(): AlertDelivery
{
    $event = app(AlertDispatcher::class)->dispatch('purchase_receipt_overdue', [
        'invoice_no' => '#1', 'supplier_name' => 'پخش تهران', 'outstanding_qty' => 3, 'days_overdue' => 6,
    ], test()->supplier, url: '/new-buy-order/1');

    return $event->deliveries()->where('user_id', test()->admin->id)->where('channel', 'in_app')->first();
}

it('shows an unread in-app alert in the dashboard, marks it read on click, and excludes resolved ones', function () {
    $delivery = raiseOverdueAlert();

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('دریافت معوق کالای خرید');

    $this->actingAs($this->admin)
        ->get(route('notifications.alerts.open', $delivery))
        ->assertRedirect('/new-buy-order/1');

    expect($delivery->refresh()->read_at)->not->toBeNull();

    $delivery->update(['resolved_at' => now(), 'read_at' => null]);
    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('دریافت معوق کالای خرید');
});

it('lists the users own alert deliveries on the alerts page and marks them all read', function () {
    $delivery = raiseOverdueAlert();

    $this->actingAs($this->admin)->get(route('notifications.alerts'))
        ->assertOk()
        ->assertSee('پخش تهران');

    expect($delivery->refresh()->read_at)->not->toBeNull();
});

it('404s opening another users alert delivery', function () {
    $delivery = raiseOverdueAlert();
    $other = User::factory()->create()->assignRole('admin');

    $this->actingAs($other)->get(route('notifications.alerts.open', $delivery))->assertNotFound();
});
