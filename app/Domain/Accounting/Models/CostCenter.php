<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
