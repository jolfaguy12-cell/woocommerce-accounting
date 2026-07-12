<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PartyPayment extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'date',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function applied(): MorphTo
    {
        return $this->morphTo();
    }

    public function settlements(): MorphMany
    {
        return $this->morphMany(CreditOrderSettlement::class, 'source');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Only the freely-editable note field is logged — every other field is set once at creation via a journal-posting service and never touched again. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
