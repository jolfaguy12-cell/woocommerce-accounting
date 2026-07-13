<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\Setting;
use App\Domain\Alerts\Models\AlertDelivery;
use App\Domain\Alerts\Services\AlertDispatcher;
use App\Domain\Alerts\Services\TelegramNotifier;
use App\Jobs\SendTelegramAlertJob;
use App\Models\User;
use Database\Seeders\AlertTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed([RoleSeeder::class, AlertTypeSeeder::class]);
    $this->admin = User::factory()->create(['telegram_id' => '999888'])->assignRole('admin');
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
    Setting::setEncrypted('telegram_bot_token', '123456:FAKE-TOKEN-FOR-TESTS');
});

function overdueDelivery(): AlertDelivery
{
    $event = app(AlertDispatcher::class)->dispatch('purchase_receipt_overdue', [
        'invoice_no' => '#1', 'supplier_name' => 'پخش تهران', 'outstanding_qty' => 5, 'days_overdue' => 6,
    ], test()->supplier);

    return $event->deliveries()->where('user_id', test()->admin->id)->where('channel', 'telegram')->first();
}

it('sends the message and marks the delivery sent on success', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $delivery = overdueDelivery();

    (new SendTelegramAlertJob($delivery->id))->handle(app(TelegramNotifier::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), '123456:FAKE-TOKEN-FOR-TESTS/sendMessage')
        && $request['chat_id'] === '999888');

    expect($delivery->refresh()->status)->toBe('sent')
        ->and($delivery->sent_at)->not->toBeNull();
});

it('marks the delivery failed on a Telegram API error and lets the job retry', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bot was blocked by the user'], 403)]);
    $delivery = overdueDelivery();

    expect(fn () => (new SendTelegramAlertJob($delivery->id))->handle(app(TelegramNotifier::class)))
        ->toThrow(RuntimeException::class);

    expect($delivery->refresh()->status)->toBe('failed')
        ->and($delivery->error)->toContain('blocked');
});

it('finalizes the delivery as failed once retries are exhausted', function () {
    $delivery = overdueDelivery();

    (new SendTelegramAlertJob($delivery->id))->failed(new Exception('timeout'));

    expect($delivery->refresh()->status)->toBe('failed')
        ->and($delivery->error)->toBe('timeout');
});

it('skips a delivery with no telegram_id set on the user without erroring', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $noTelegram = User::factory()->create(['telegram_id' => null])->assignRole('accountant');
    $event = app(AlertDispatcher::class)->dispatch('purchase_receipt_overdue', [
        'invoice_no' => '#2', 'supplier_name' => 'پخش تهران', 'outstanding_qty' => 1, 'days_overdue' => 6,
    ], $this->supplier);
    $delivery = $event->deliveries()->where('user_id', $noTelegram->id)->where('channel', 'telegram')->first();

    expect($delivery->status)->toBe('skipped_no_telegram_id');

    // Admin (who does have telegram_id) already triggered exactly one real send via
    // dispatch()'s own synchronous job dispatch — re-running the job for the
    // no-telegram-id delivery must not add a second one.
    Http::assertSentCount(1);

    (new SendTelegramAlertJob($delivery->id))->handle(app(TelegramNotifier::class));

    Http::assertSentCount(1);
});
