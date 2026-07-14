<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    $this->payment = app(PaymentRecorder::class)->pay($this->supplier, 100_000, $this->bank->id, $this->admin->id);
});

it('edits a payment note, sets the editor, and logs the change to activitylog', function () {
    $editor = User::factory()->create()->assignRole('accountant');

    $this->actingAs($editor)->put("/party-payments/{$this->payment->id}/note", ['note' => 'یادداشت جدید'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->payment->refresh();
    expect($this->payment->note)->toBe('یادداشت جدید')
        ->and($this->payment->updated_by)->toBe($editor->id);

    $activity = Activity::where('subject_type', $this->payment->getMorphClass())->where('subject_id', $this->payment->id)->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($editor->id)
        ->and($activity->changes()['attributes']['note'])->toBe('یادداشت جدید');
});

it('forbids warehouse from editing a payment note', function () {
    $this->actingAs($this->warehouse)->put("/party-payments/{$this->payment->id}/note", ['note' => 'x'])
        ->assertForbidden();
});
