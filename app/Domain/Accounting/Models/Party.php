<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Accounting\Support\PhoneNormalizer;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Orders\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One real person or company = one Party, holding any number of simultaneous
 * roles (customer, supplier, employee, partner, other) via `party_roles`.
 *
 * `type` is the legacy single-role column. It is still written on creation
 * because it is NOT NULL, but nothing reads it for authorization or filtering
 * any more — roles come from party_roles. It is dropped in its own, final
 * migration once no runtime dependency on it remains.
 */
class Party extends Model
{
    protected $guarded = [];

    protected $casts = [
        'credit_limit' => 'integer',
        'is_wholesale' => 'boolean',
        'wholesale_labeled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // One canonical phone form for every party however it was created
        // (sync, import, UI) — duplicate detection indexes this column.
        static::saving(function (Party $party) {
            if ($party->isDirty('phone')) {
                $party->normalized_phone = PhoneNormalizer::normalize($party->phone);
            }
        });

        // Transitional bridge: seed the party's first role from the legacy
        // `type` it was created with, so every creation path (order sync,
        // supplier form, tests) produces a party_roles row without each of them
        // having to know about roles yet. This is a one-way bootstrap at insert
        // time — it never claims `type` can represent a second role, and it is
        // removed together with the column.
        static::created(function (Party $party) {
            if ($party->type) {
                $party->activateRole($party->type);
            }
        });
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'supplier_party_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_party_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(PartyRole::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PartyBankAccount::class);
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(PartyExternalId::class);
    }

    /** @return Collection<int, PartyRole> */
    public function activeRoles(): Collection
    {
        return $this->relationLoaded('roles')
            ? $this->roles->where('is_active', true)->values()
            : $this->roles()->active()->get();
    }

    public function hasRole(string|PartyRoleType $role): bool
    {
        $role = PartyRoleType::coerce($role)->value;

        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(fn (PartyRole $r) => $r->role === $role && $r->is_active);
        }

        return $this->roles()->active()->where('role', $role)->exists();
    }

    /** Party::withRole('customer') — the replacement for every Party::where('type', 'customer'). */
    public function scopeWithRole(Builder $query, string|PartyRoleType $role): void
    {
        $role = PartyRoleType::coerce($role)->value;

        $query->whereHas('roles', fn (Builder $q) => $q->where('role', $role)->where('is_active', true));
    }

    /** Idempotent: re-activating a role the party once lost updates that same row, never inserts a second one. */
    public function activateRole(string|PartyRoleType $role, ?int $by = null): PartyRole
    {
        $role = PartyRoleType::coerce($role);

        $partyRole = $this->roles()->firstOrNew(['role' => $role->value]);

        $partyRole->fill([
            'is_active' => true,
            'activated_at' => now(),
            'activated_by' => $by,
            'deactivated_at' => null,
            'deactivated_by' => null,
        ])->save();

        $this->unsetRelation('roles');

        return $partyRole;
    }

    /** Never deletes: the party, its other roles and all of its journal history stay exactly as they were. */
    public function deactivateRole(string|PartyRoleType $role, ?int $by = null): ?PartyRole
    {
        $role = PartyRoleType::coerce($role);

        $partyRole = $this->roles()->where('role', $role->value)->first();

        if (! $partyRole || ! $partyRole->is_active) {
            return $partyRole;
        }

        $partyRole->fill([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivated_by' => $by,
        ])->save();

        $this->unsetRelation('roles');

        return $partyRole;
    }
}
