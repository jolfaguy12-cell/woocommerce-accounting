<?php

use App\Domain\Alerts\Models\AlertType;
use App\Domain\Alerts\Services\AlertDispatcher;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->alertType = AlertType::create([
        'code' => 'test_alert',
        'name' => 'هشدار تستی',
        'message_template' => 'سفارش #{order_id} به مبلغ {amount} تومان.',
        'is_active' => true,
    ]);
    $this->alertType->syncRoles(['admin', 'accountant']);
});

it('renders template placeholders and only delivers to role-matching users with a telegram_id', function () {
    $adminWithTelegram = User::factory()->create(['telegram_id' => '111'])->assignRole('admin');
    $adminWithoutTelegram = User::factory()->create(['telegram_id' => null])->assignRole('admin');
    $warehouseWithTelegram = User::factory()->create(['telegram_id' => '222'])->assignRole('warehouse');

    $event = app(AlertDispatcher::class)->dispatch('test_alert', ['order_id' => 55, 'amount' => '1,000']);

    expect($event->rendered_message)->toBe('سفارش #55 به مبلغ 1,000 تومان.')
        ->and($event->status)->toBe('dispatched');

    $deliveries = $event->deliveries;
    expect($deliveries)->toHaveCount(2);

    $adminDelivery = $deliveries->firstWhere('user_id', $adminWithTelegram->id);
    expect($adminDelivery->status)->toBe('pending')->and($adminDelivery->channel)->toBe('telegram');

    $noTelegramDelivery = $deliveries->firstWhere('user_id', $adminWithoutTelegram->id);
    expect($noTelegramDelivery->status)->toBe('skipped_no_telegram_id');

    expect($deliveries->firstWhere('user_id', $warehouseWithTelegram->id))->toBeNull();
});

it('does nothing when the alert type is inactive', function () {
    $this->alertType->update(['is_active' => false]);
    User::factory()->create(['telegram_id' => '111'])->assignRole('admin');

    $event = app(AlertDispatcher::class)->dispatch('test_alert', ['order_id' => 1, 'amount' => '0']);

    expect($event->status)->toBe('skipped_inactive')
        ->and($event->deliveries)->toHaveCount(0);
});

it('returns null and does not throw for an unknown alert code', function () {
    $result = app(AlertDispatcher::class)->dispatch('does_not_exist', []);

    expect($result)->toBeNull();
});
