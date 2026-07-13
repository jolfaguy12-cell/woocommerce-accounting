<?php

namespace App\Domain\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A counterparty's own bank account — external information about where their
 * money lives. It has no account_id and can never be posted to; internal
 * cash/bank accounts are App\Domain\Expenses\Models\BankAccount.
 */
class PartyBankAccount extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['bank_name', 'account_holder', 'account_number', 'card_number', 'iban', 'is_default', 'is_active', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
