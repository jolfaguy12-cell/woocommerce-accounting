<?php

use App\Domain\Expenses\Models\Attachment;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $bank = app(BankAccountManager::class)->create(['name' => 'بانک تست']);
    $category = ExpenseCategory::create(['name' => 'عمومی', 'slug' => 'general', 'account_code' => '6000']);

    $this->expense = app(ExpenseRecorder::class)->record([
        'expense_category_id' => $category->id,
        'bank_account_id' => $bank->id,
        'amount' => 250_000,
        'expense_date' => Carbon::now('Asia/Tehran'),
        'description' => 'با رسید',
    ]);

    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->partner = User::factory()->create()->assignRole('partner_viewer');
});

it('stores uploaded receipts on the private disk', function () {
    $response = $this->actingAs($this->accountant)->post(route('attachments.store'), [
        'attachable_type' => 'expense',
        'attachable_id' => $this->expense->id,
        'file' => UploadedFile::fake()->create('receipt.pdf', 120, 'application/pdf'),
    ]);

    $response->assertRedirect();
    $attachment = Attachment::first();
    expect($attachment)->not->toBeNull()
        ->and($attachment->attachable_id)->toBe($this->expense->id);
    Storage::disk('local')->assertExists($attachment->path);
});

it('lets accountants download attachments but blocks partner viewers', function () {
    $attachment = Attachment::create([
        'attachable_type' => 'expense',
        'attachable_id' => $this->expense->id,
        'path' => UploadedFile::fake()->create('r.pdf', 10)->store('attachments', 'local'),
        'original_name' => 'r.pdf',
        'mime_type' => 'application/pdf',
        'size' => 10,
        'uploaded_by' => $this->accountant->id,
    ]);

    $this->actingAs($this->accountant)->get(route('attachments.download', $attachment))->assertOk();
    $this->actingAs($this->partner)->get(route('attachments.download', $attachment))->assertForbidden();
});
