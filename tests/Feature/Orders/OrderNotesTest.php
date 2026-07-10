<?php

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderNote;
use App\Domain\Orders\Models\OrderNoteRecipient;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, ChannelSeeder::class]);
    $this->author = User::factory()->create()->assignRole('admin');
    $this->colleague = User::factory()->create()->assignRole('accountant');

    ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    app(OrderIngestPipeline::class)->ingest(6001, [
        'id' => 6001, 'status' => 'pending', 'total' => 100000, 'date_created' => '2026-07-08T10:00:00',
        'line_items' => [['id' => 1, 'name' => 'اسپری', 'quantity' => 1, 'subtotal' => 100000, 'total' => 100000, 'product_id' => 5732, 'variation_id' => null]],
    ], 'manual');
    $this->order = Order::firstWhere('hub_order_id', 6001);
});

it('adds a note to an order without recipients', function () {
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", ['body' => 'یادداشت بدون گیرنده'])
        ->assertRedirect();

    $note = OrderNote::first();
    expect($note->body)->toBe('یادداشت بدون گیرنده')
        ->and($note->created_by)->toBe($this->author->id)
        ->and($note->recipients)->toHaveCount(0);
});

it('assigns a note to another user, who sees it as an unread notification', function () {
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", [
        'body' => 'لطفا بررسی کن',
        'recipients' => [$this->colleague->id],
    ])->assertRedirect();

    $recipient = OrderNoteRecipient::first();
    expect($recipient->user_id)->toBe($this->colleague->id)
        ->and($recipient->read_at)->toBeNull();
});

it('does not create a recipient row when a user assigns a note to themselves', function () {
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", [
        'body' => 'خودم یادداشت میگیرم',
        'recipients' => [$this->author->id],
    ])->assertRedirect();

    expect(OrderNoteRecipient::count())->toBe(0);
});

it('visiting the notes inbox marks the current user\'s assigned notes as read', function () {
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", [
        'body' => 'یادداشت مهم', 'recipients' => [$this->colleague->id],
    ]);

    expect(OrderNoteRecipient::first()->read_at)->toBeNull();

    $this->actingAs($this->colleague)->get('/notifications/notes')->assertOk();

    expect(OrderNoteRecipient::first()->read_at)->not->toBeNull();
});

it('the notes inbox shows notes I authored and notes assigned to me, not other unrelated notes', function () {
    $stranger = User::factory()->create()->assignRole('warehouse');

    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", ['body' => 'یادداشت من']);
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", ['body' => 'یادداشت محول‌شده', 'recipients' => [$this->colleague->id]]);
    $this->actingAs($stranger)->post("/orders/{$this->order->id}/notes", ['body' => 'یادداشت بی‌ربط']);

    $response = $this->actingAs($this->colleague)->get('/notifications/notes');

    $response->assertOk()
        ->assertSee('یادداشت محول‌شده')
        ->assertDontSee('یادداشت من')
        ->assertDontSee('یادداشت بی‌ربط');
});

it('lets the author delete their own note', function () {
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", ['body' => 'حذف میشه']);
    $note = OrderNote::first();

    $this->actingAs($this->author)->delete("/notes/{$note->id}")->assertRedirect();

    expect(OrderNote::find($note->id))->toBeNull();
});

it('blocks a non-author, non-admin from deleting someone else\'s note', function () {
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", ['body' => 'یادداشت من']);
    $note = OrderNote::first();

    $this->actingAs($this->colleague)->delete("/notes/{$note->id}")->assertForbidden();

    expect(OrderNote::find($note->id))->not->toBeNull();
});

it('lets an admin delete any note regardless of authorship', function () {
    $this->actingAs($this->colleague)->post("/orders/{$this->order->id}/notes", ['body' => 'یادداشت همکار']);
    $note = OrderNote::first();

    $this->actingAs($this->author)->delete("/notes/{$note->id}")->assertRedirect();

    expect(OrderNote::find($note->id))->toBeNull();
});

it('the sidebar badge counts unread assigned notes and drops after they are viewed', function () {
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", [
        'body' => 'یک', 'recipients' => [$this->colleague->id],
    ]);
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", [
        'body' => 'دو', 'recipients' => [$this->colleague->id],
    ]);

    expect(OrderNoteRecipient::where('user_id', $this->colleague->id)->whereNull('read_at')->count())->toBe(2);

    $this->actingAs($this->colleague)->get('/orders')->assertOk(); // sidebar renders here too, must not error

    $this->actingAs($this->colleague)->get('/notifications/notes')->assertOk();

    expect(OrderNoteRecipient::where('user_id', $this->colleague->id)->whereNull('read_at')->count())->toBe(0);
});

it('cascades note deletion when its order is deleted', function () {
    $this->actingAs($this->author)->post("/orders/{$this->order->id}/notes", [
        'body' => 'یادداشت', 'recipients' => [$this->colleague->id],
    ]);

    $this->order->delete();

    expect(OrderNote::count())->toBe(0)
        ->and(OrderNoteRecipient::count())->toBe(0);
});
