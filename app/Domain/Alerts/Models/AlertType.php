<?php

namespace App\Domain\Alerts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class AlertType extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /** @return array<int, string> */
    public function getRolesAttribute(): array
    {
        return DB::table('alert_type_role')
            ->where('alert_type_id', $this->id)
            ->pluck('role')
            ->all();
    }

    public function syncRoles(array $roles): void
    {
        DB::table('alert_type_role')->where('alert_type_id', $this->id)->delete();

        foreach (array_unique($roles) as $role) {
            DB::table('alert_type_role')->insert([
                'alert_type_id' => $this->id,
                'role' => $role,
            ]);
        }
    }

    /** @return array<int, string> placeholder keys documented per alert type for the template-editor UI */
    public function placeholders(): array
    {
        return match ($this->code) {
            'zibal_gateway_mismatch' => ['order_id', 'order_status', 'gateway_status', 'amount'],
            'zibal_new_bank_account_detected' => ['iban', 'holder_name'],
            'purchase_receipt_overdue' => ['invoice_no', 'supplier_name', 'outstanding_qty', 'days_overdue'],
            default => [],
        };
    }
}
