<?php

use App\Domain\Alerts\Models\AlertType;
use App\Models\User;
use Database\Seeders\AlertTypeSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, AlertTypeSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
});

it('lets an admin toggle an alert type, change its target roles and edit its template', function () {
    $type = AlertType::firstWhere('code', 'zibal_gateway_mismatch');
    expect($type->is_active)->toBeTrue();

    $this->actingAs($this->admin)->post(route('tools.alerts.toggle', $type))
        ->assertRedirect();
    expect($type->refresh()->is_active)->toBeFalse();

    $this->actingAs($this->admin)->post(route('tools.alerts.roles', $type), ['roles' => ['admin']])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($type->refresh()->roles)->toBe(['admin']);

    $this->actingAs($this->admin)->post(route('tools.alerts.template', $type), ['message_template' => 'متن جدید {order_id}'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($type->refresh()->message_template)->toBe('متن جدید {order_id}');
});
