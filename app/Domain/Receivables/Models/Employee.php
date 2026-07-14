<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The employment facts about a Party that holds the employee role — and nothing
 * else. Their name, phone, national id and bank accounts live on the Party, because
 * an employee is a person we also employ, not a separate kind of record.
 *
 * `base_salary` is a CONTRACT figure, not a balance. It is what the payroll form
 * proposes; what the employee is actually owed is «مانده حقوق», which is read from
 * account 2300 in the ledger. The two disagree the moment a period is accrued at a
 * different amount — and when they do, the ledger is right.
 */
class Employee extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'base_salary' => 'integer',
        'hired_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /** A change to somebody's pay is exactly the kind of edit that must leave a trail. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['base_salary', 'job_title', 'hired_at', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('employee')
            ->dontSubmitEmptyLogs();
    }
}
