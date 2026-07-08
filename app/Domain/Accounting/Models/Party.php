<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class Party extends Model
{
    protected $guarded = [];

    protected $casts = [
        'credit_limit' => 'integer',
    ];
}
