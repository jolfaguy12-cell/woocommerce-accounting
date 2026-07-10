<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Alerts\Models\AlertEvent;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\BankDeposit;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ZibalDepositImporter;
use App\Models\User;
use Database\Seeders\AlertTypeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, AlertTypeSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
});

function makeZibalExportFile(array $rows): UploadedFile
{
    $headers = ['مبلغ', 'بانک مبدا/psp', 'وضعیت', 'تاریخ ثبت', 'تاریخ واریز', 'شماره شبا', 'صاحب حساب', 'شناسه مرجع تراکنش', 'شناسه پیگیری', 'کارمزد', 'درخواست‌های تسویه مرتبط', 'توضیحات'];

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('گزارش تسویه');
    $sheet->fromArray($headers, null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'zibal').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'zibal_checkout_test.xlsx', null, null, true);
}

it('imports successful rows as balanced journal entries against a matched bank account', function () {
    $bank = app(BankAccountManager::class)->create([
        'name' => 'بانک مهر ایران',
        'iban' => 'IR390600520870014443024001',
    ]);

    $file = makeZibalExportFile([
        [4608740, 'پرداخت‌الکترونیک سامان کیش', 'موفق', '1405/04/13-00:23:11', '1405/04/13-09:45:00', 'IR390600520870014443024001', 'لطيفه خليلي', '05041341100000001692265308266', null, 0, '22961691', null],
    ]);

    $import = app(ZibalDepositImporter::class)->import($file, $this->admin);

    expect($import->new_count)->toBe(1)
        ->and($import->duplicate_count)->toBe(0)
        ->and($import->new_bank_accounts_count)->toBe(0);

    $deposit = BankDeposit::firstWhere('external_reference', '05041341100000001692265308266');
    expect($deposit)->not->toBeNull()
        ->and($deposit->amount_toman)->toBe(460874) // 4,608,740 Rial / 10
        // Regression guard: deposited_at must come from the sheet's own
        // "تاریخ واریز" (Jalali 1405/04/13 09:45 -> Gregorian 2026-07-04),
        // never silently default to "now" (see date-parsing bug fixed 2026-07-10).
        ->and($deposit->deposited_at->toDateTimeString())->toBe('2026-07-04 09:45:00')
        ->and($deposit->bank_account_id)->toBe($bank->id)
        ->and($deposit->isPosted())->toBeTrue();

    $entry = $deposit->journalEntry;
    $debits = $entry->lines->sum('debit');
    $credits = $entry->lines->sum('credit');
    expect($debits)->toBe($credits)->and($debits)->toBe(460874);

    expect($bank->account->balance())->toBe(460874);
});

it('is idempotent when the same file is imported twice', function () {
    app(BankAccountManager::class)->create([
        'name' => 'بانک مهر ایران',
        'iban' => 'IR390600520870014443024001',
    ]);

    $row = [4608740, 'پرداخت‌الکترونیک سامان کیش', 'موفق', '1405/04/13-00:23:11', '1405/04/13-09:45:00', 'IR390600520870014443024001', 'لطيفه خليلي', 'ref-dup-1', null, 0, null, null];

    app(ZibalDepositImporter::class)->import(makeZibalExportFile([$row]), $this->admin);
    $second = app(ZibalDepositImporter::class)->import(makeZibalExportFile([$row]), $this->admin);

    expect($second->new_count)->toBe(0)
        ->and($second->duplicate_count)->toBe(1)
        ->and(BankDeposit::where('external_reference', 'ref-dup-1')->count())->toBe(1)
        ->and(JournalEntry::where('idempotency_key', 'zibal_deposit:ref-dup-1')->count())->toBe(1);
});

it('auto-creates a bank account for an unknown destination IBAN and fires an alert', function () {
    $row = [1000000, 'به‌پرداخت ملت', 'موفق', '1405/04/13-00:23:11', '1405/04/13-09:45:00', 'IR000000000000000000000099', 'حساب جدید', 'ref-new-acc', null, 0, null, null];

    expect(BankAccount::where('iban', 'IR000000000000000000000099')->exists())->toBeFalse();

    $import = app(ZibalDepositImporter::class)->import(makeZibalExportFile([$row]), $this->admin);

    expect($import->new_bank_accounts_count)->toBe(1);
    $bank = BankAccount::where('iban', 'IR000000000000000000000099')->first();
    expect($bank)->not->toBeNull();

    $event = AlertEvent::whereHas('alertType', fn ($q) => $q->where('code', 'zibal_new_bank_account_detected'))->first();
    expect($event)->not->toBeNull()
        ->and($event->rendered_message)->toContain('IR000000000000000000000099');
});

it('records non-successful rows without posting a journal entry', function () {
    app(BankAccountManager::class)->create([
        'name' => 'بانک مهر ایران',
        'iban' => 'IR390600520870014443024001',
    ]);

    $row = [500000, 'به‌پرداخت ملت', 'ناموفق', '1405/04/13-00:23:11', '1405/04/13-09:45:00', 'IR390600520870014443024001', 'لطيفه خليلي', 'ref-failed-1', null, 0, null, null];

    app(ZibalDepositImporter::class)->import(makeZibalExportFile([$row]), $this->admin);

    $deposit = BankDeposit::firstWhere('external_reference', 'ref-failed-1');
    expect($deposit)->not->toBeNull()
        ->and($deposit->isPosted())->toBeFalse();
});

it('never guesses a deposit date when the source date cannot be parsed', function () {
    app(BankAccountManager::class)->create([
        'name' => 'بانک مهر ایران',
        'iban' => 'IR390600520870014443024001',
    ]);

    $row = [500000, 'به‌پرداخت ملت', 'موفق', '1405/04/13-00:23:11', 'not-a-date', 'IR390600520870014443024001', 'لطيفه خليلي', 'ref-bad-date', null, 0, null, null];

    $import = app(ZibalDepositImporter::class)->import(makeZibalExportFile([$row]), $this->admin);

    expect($import->new_count)->toBe(0)
        ->and($import->date_parse_failed_count)->toBe(1)
        ->and(BankDeposit::where('external_reference', 'ref-bad-date')->exists())->toBeFalse();
});

it('renders the deposits list page for an admin, with and without filters', function () {
    $bank = app(BankAccountManager::class)->create(['name' => 'بانک مهر ایران', 'iban' => 'IR390600520870014443024001']);
    app(ZibalDepositImporter::class)->import(makeZibalExportFile([
        [4608740, 'پرداخت‌الکترونیک سامان کیش', 'موفق', '1405/04/13-00:23:11', '1405/04/13-09:45:00', 'IR390600520870014443024001', 'لطيفه خليلي', 'ref-render-1', null, 0, null, null],
    ]), $this->admin);

    $this->actingAs($this->admin)->get('/bank-accounts/deposits')
        ->assertOk()
        ->assertSee('ref-render-1', false);

    $this->actingAs($this->admin)->get('/bank-accounts/deposits?'.http_build_query(['bank_account_id' => $bank->id, 'status' => 'موفق']))
        ->assertOk()
        ->assertSee('ref-render-1', false);
});

it('forbids non admin/accountant roles from importing deposits', function () {
    $warehouse = User::factory()->create()->assignRole('warehouse');

    $this->actingAs($warehouse)->get('/bank-accounts/deposits')->assertForbidden();
});
