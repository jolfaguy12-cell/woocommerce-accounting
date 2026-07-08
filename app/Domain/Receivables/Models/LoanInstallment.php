<?php

namespace App\Domain\Receivables\Models;

use Illuminate\Database\Eloquent\Model;

class LoanInstallment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'principal_part' => 'integer',
        'interest_part' => 'integer',
        'paid_at' => 'date',
        'due_date' => 'date',
    ];
}
