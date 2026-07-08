<?php

namespace App\Domain\Receivables\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gross' => 'integer',
        'advances_deducted' => 'integer',
        'net' => 'integer',
    ];
}
