<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Alerts\Services\AlertDispatcher;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\BankDeposit;
use App\Domain\Expenses\Models\BankDepositImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports a Zibal settlement-report export ("گزارش تسویه") and turns each
 * successful row into a real bank-ledger transaction. There is no Zibal API
 * for this — the merchant key in .env only supports per-transaction
 * inquiry/verify, not a settlement list — so the user exports this sheet
 * from the Zibal dashboard by hand and uploads it here.
 */
class ZibalDepositImporter
{
    private const SOURCE = 'zibal_export';

    private const CLEARING_ACCOUNT_CODE = '1150';

    private const GATEWAY_FEE_ACCOUNT_CODE = '5300';

    private const SUCCESS_STATUS = 'موفق';

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly BankAccountManager $bankAccounts,
        private readonly AlertDispatcher $alerts,
    ) {}

    public function import(UploadedFile $file, User $user): BankDepositImport
    {
        $rows = $this->readRows($file);

        return DB::transaction(function () use ($rows, $file, $user) {
            $import = BankDepositImport::create([
                'filename' => $file->getClientOriginalName(),
                'uploaded_by' => $user->id,
                'row_count' => count($rows),
            ]);

            $newCount = 0;
            $duplicateCount = 0;
            $newBankAccountsCount = 0;
            $dateParseFailedCount = 0;

            foreach ($rows as $row) {
                $reference = trim((string) $row['شناسه مرجع تراکنش']);
                if ($reference === '') {
                    continue;
                }

                if (BankDeposit::where('source', self::SOURCE)->where('external_reference', $reference)->exists()) {
                    $duplicateCount++;

                    continue;
                }

                // A row whose deposit date we can't parse must never be guessed
                // (e.g. defaulted to "now") — that would silently corrupt a real
                // ledger date. Skip it and surface the count instead.
                $depositedAt = $this->parseJalaliDateTime($row['تاریخ واریز'] ?? null);
                if (! $depositedAt) {
                    $dateParseFailedCount++;

                    continue;
                }

                [$bankAccount, $wasCreated] = $this->resolveBankAccount($row);
                if ($wasCreated) {
                    $newBankAccountsCount++;
                }

                $deposit = $this->createDeposit($import, $row, $reference, $bankAccount, $depositedAt);

                if ($deposit->status === self::SUCCESS_STATUS) {
                    $this->postJournal($deposit, $bankAccount);
                }

                if ($wasCreated) {
                    $this->alerts->dispatch('zibal_new_bank_account_detected', [
                        'iban' => $bankAccount->iban,
                        'holder_name' => $bankAccount->name,
                    ], $bankAccount);
                }

                $newCount++;
            }

            $import->update([
                'new_count' => $newCount,
                'duplicate_count' => $duplicateCount,
                'new_bank_accounts_count' => $newBankAccountsCount,
                'date_parse_failed_count' => $dateParseFailedCount,
            ]);

            return $import->fresh();
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function readRows(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);

        if (count($data) < 2) {
            return [];
        }

        $headers = array_map('trim', $data[0]);
        $rows = [];

        foreach (array_slice($data, 1) as $line) {
            if (count(array_filter($line, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($headers, $line);
        }

        return $rows;
    }

    /** @return array{0: BankAccount, 1: bool} */
    private function resolveBankAccount(array $row): array
    {
        $iban = trim((string) ($row['شماره شبا'] ?? ''));
        $holder = trim((string) ($row['صاحب حساب'] ?? '')) ?: $iban;

        if ($iban !== '' && $existing = BankAccount::where('iban', $iban)->first()) {
            return [$existing, false];
        }

        $created = $this->bankAccounts->create([
            'name' => $holder !== '' ? $holder : $iban,
            'iban' => $iban !== '' ? $iban : null,
            'is_cash' => false,
        ]);

        return [$created, true];
    }

    private function createDeposit(BankDepositImport $import, array $row, string $reference, BankAccount $bankAccount, Carbon $depositedAt): BankDeposit
    {
        return BankDeposit::create([
            'import_id' => $import->id,
            'source' => self::SOURCE,
            'external_reference' => $reference,
            'bank_account_id' => $bankAccount->id,
            'destination_iban' => trim((string) ($row['شماره شبا'] ?? '')) ?: null,
            'account_holder_name' => trim((string) ($row['صاحب حساب'] ?? '')) ?: null,
            'psp_label' => trim((string) ($row['بانک مبدا/psp'] ?? '')) ?: null,
            'status' => trim((string) ($row['وضعیت'] ?? '')) ?: null,
            'amount_toman' => $this->toToman($row['مبلغ'] ?? 0),
            'fee_toman' => $this->toToman($row['کارمزد'] ?? 0),
            'registered_at' => $this->parseJalaliDateTime($row['تاریخ ثبت'] ?? null),
            'deposited_at' => $depositedAt,
            'tracking_id' => trim((string) ($row['شناسه پیگیری'] ?? '')) ?: null,
            'related_settlement_ids' => trim((string) ($row['درخواست‌های تسویه مرتبط'] ?? '')) ?: null,
            'raw_row' => $row,
        ]);
    }

    private function postJournal(BankDeposit $deposit, BankAccount $bankAccount): void
    {
        $lines = [
            ['account' => $bankAccount->account_id, 'debit' => $deposit->amount_toman],
        ];

        if ($deposit->fee_toman > 0) {
            $lines[] = ['account' => self::GATEWAY_FEE_ACCOUNT_CODE, 'debit' => $deposit->fee_toman];
        }

        $lines[] = [
            'account' => self::CLEARING_ACCOUNT_CODE,
            'credit' => $deposit->amount_toman + $deposit->fee_toman,
        ];

        $entry = $this->poster->post([
            'entry_date' => $deposit->deposited_at,
            'description' => "واریزی زیبال - {$deposit->account_holder_name} - {$deposit->psp_label}",
            'idempotency_key' => "zibal_deposit:{$deposit->external_reference}",
            'source' => $deposit,
        ], $lines);

        $deposit->update(['journal_entry_id' => $entry->id]);
    }

    private function toToman(mixed $rial): int
    {
        return intdiv((int) round((float) $rial), 10);
    }

    private function parseJalaliDateTime(?string $raw): ?Carbon
    {
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::instance(Jalalian::fromFormat('Y/m/d-H:i:s', trim($raw))->toCarbon())->setTimezone(JalaliPeriod::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }
}
