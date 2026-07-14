<?php

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Expense extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'expense_date' => 'date',
        'affects_partner_profit' => 'boolean',
        'is_capital' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** Who the expense is ABOUT — the vendor it was spent with. */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** Who PAID for it — an employee, a partner, or the supplier we now owe. */
    public function fundedByParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'funded_by_party_id');
    }

    public function fundingSource(): ExpenseFundingSource
    {
        return ExpenseFundingSource::from($this->funding_source ?? ExpenseFundingSource::Bank->value);
    }

    /** True when the company still owes this expense to somebody. */
    public function isLiability(): bool
    {
        return $this->fundingSource() !== ExpenseFundingSource::Bank;
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
