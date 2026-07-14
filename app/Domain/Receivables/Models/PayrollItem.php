<?php

namespace App\Domain\Receivables\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gross' => 'integer',
        'advances_deducted' => 'integer',
        'net' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The run this line belongs to — how "was this employee already accrued this period?" is asked. */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }
}
