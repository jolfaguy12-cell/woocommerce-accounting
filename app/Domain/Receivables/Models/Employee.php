<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    protected $guarded = [];

    protected $casts = [
        'base_salary' => 'integer',
        'is_active' => 'boolean',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
