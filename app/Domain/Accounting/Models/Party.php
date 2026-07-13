<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Accounting\Support\PhoneNormalizer;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Orders\Models\Order;
use App\Domain\Receivables\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

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
    /**
     * Columns whose data now lives on a role profile (or party_bank_accounts).
     * They still exist on `parties` until their final drop migration, and the
     * accessors below READ from the profile — so a write here would land in a
     * column nobody reads and be silently lost. Rejected outright instead: a
     * loud failure now beats a wholesale flag that quietly stops working.
     */
    public const LEGACY_ROLE_COLUMNS = [
        'credit_limit',
        'is_wholesale',
        'wholesale_labeled_at',
        'wholesale_labeled_by',
        'shop_name',
        'bank_account_number',
    ];

    protected $guarded = [];

    protected $casts = [];

    /*
     |--------------------------------------------------------------------------
     | Role data that used to live on `parties`
     |--------------------------------------------------------------------------
     | credit_limit / is_wholesale / wholesale_labeled_* are customer data,
     | shop_name is supplier data, bank_account_number was one supplier bank
     | account. They now live on the role profile / party_bank_accounts, and the
     | accessors below read them from there so that views, Alpine payloads and
     | `$party->only([...])` keep working while a role's data has exactly one home.
     |
     | These are READ shims only — writes go through the profile explicitly (see
     | CustomerController::setWholesale, SupplierController::update). The legacy
     | columns still exist on `parties` but are no longer read or written; they
     | are dropped in their own, final migration.
     */

    public function getIsWholesaleAttribute(): bool
    {
        return (bool) $this->customerProfile?->is_wholesale;
    }

    public function getWholesaleLabeledAtAttribute(): ?Carbon
    {
        return $this->customerProfile?->wholesale_labeled_at;
    }

    public function getWholesaleLabeledByAttribute(): ?int
    {
        return $this->customerProfile?->wholesale_labeled_by;
    }

    public function getCreditLimitAttribute(): int
    {
        return (int) ($this->customerProfile?->credit_limit ?? 0);
    }

    public function getShopNameAttribute(): ?string
    {
        return $this->supplierProfile?->shop_name;
    }

    public function getBankAccountNumberAttribute(): ?string
    {
        $accounts = $this->bankAccounts->where('is_active', true);

        return ($accounts->firstWhere('is_default', true) ?? $accounts->first())?->account_number;
    }

    protected static function booted(): void
    {
        // One canonical phone form for every party however it was created
        // (sync, import, UI) — duplicate detection indexes this column.
        static::saving(function (Party $party) {
            if ($party->isDirty('phone')) {
                $party->normalized_phone = PhoneNormalizer::normalize($party->phone);
            }

            $party->assertNoLegacyRoleWrite();
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

    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function supplierProfile(): HasOne
    {
        return $this->hasOne(SupplierProfile::class);
    }

    public function partnerProfile(): HasOne
    {
        return $this->hasOne(PartnerProfile::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * The profile for a role, created on first use — so a caller never has to
     * decide whether a party that has the role yet lacks its profile row (which
     * is exactly what every party looked like before the backfill ran).
     *
     * Never resets an existing profile: deactivating and later reactivating a
     * role returns the same profile row, with its credit limit and wholesale
     * label intact. A role can be turned off and on; its data is not a casualty.
     */
    public function profileFor(string|PartyRoleType $role): ?Model
    {
        $relation = match (PartyRoleType::coerce($role)) {
            PartyRoleType::Customer => $this->customerProfile(),
            PartyRoleType::Supplier => $this->supplierProfile(),
            PartyRoleType::Partner => $this->partnerProfile(),
            PartyRoleType::Employee => $this->employee(),
            PartyRoleType::Other => null, // «سایر» carries no role-specific data
        };

        if ($relation === null) {
            return null;
        }

        try {
            return $relation->firstOrCreate([]);
        } catch (QueryException $e) {
            // Lost the race on party_id's unique index — adopt the row the other
            // process just inserted rather than failing.
            return $relation->first() ?? throw $e;
        }
    }

    /**
     * Refuse a write to a column whose data has moved to a role profile. Such a
     * write would succeed silently against a column no reader looks at, so the
     * value would simply disappear — the failure mode this guard exists to make
     * impossible. Write through profileFor() instead.
     *
     * `type` is exempt on INSERT only: it is still NOT NULL, and the created
     * hook bootstraps the party's first role from it. Changing it afterwards is
     * meaningless — roles live in party_roles — so that is rejected too.
     */
    private function assertNoLegacyRoleWrite(): void
    {
        $dirty = array_keys($this->getDirty());

        if ($offending = array_intersect($dirty, self::LEGACY_ROLE_COLUMNS)) {
            $columns = implode(', ', $offending);

            throw new LogicException(
                "Party [{$columns}] moved to a role profile and is no longer read from `parties`. "
                .'Write it through $party->profileFor($role) (or party_bank_accounts) instead.'
            );
        }

        if ($this->exists && in_array('type', $dirty, true)) {
            throw new LogicException(
                'Party.type is the legacy single-role column and cannot be changed. '
                .'Use activateRole()/deactivateRole() — a party can hold several roles at once.'
            );
        }
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

    /**
     * Idempotent, atomic and race-safe.
     *
     * Idempotent: re-activating a role the party once lost updates that same row
     * (the unique index guarantees there is only ever one), and a role that is
     * already active is returned untouched — order sync calls this on every
     * resolve, and rewriting activated_at each time would churn the row and spam
     * the activity log with a change that changed nothing.
     *
     * Atomic: the role row and its profile are created together or not at all,
     * so a party can never end up holding a role with no profile — which a
     * whereHas filter cannot see (a brand-new customer would silently drop out
     * of the "not wholesale" list).
     *
     * Race-safe: two concurrent activations (webhook + poller resolving the same
     * customer) both try to insert; the unique index rejects the loser, which
     * then adopts the winner's row rather than failing the request. Same
     * technique JournalPoster uses for its idempotency key.
     */
    public function activateRole(string|PartyRoleType $role, ?int $by = null): PartyRole
    {
        $role = PartyRoleType::coerce($role);

        return DB::transaction(function () use ($role, $by) {
            $partyRole = $this->roles()->firstOrNew(['role' => $role->value]);

            if (! ($partyRole->exists && $partyRole->is_active)) {
                $attributes = [
                    'is_active' => true,
                    'activated_at' => now(),
                    'activated_by' => $by,
                    'deactivated_at' => null,
                    'deactivated_by' => null,
                ];

                try {
                    $partyRole->fill($attributes)->save();
                } catch (QueryException $e) {
                    $winner = $this->roles()->where('role', $role->value)->first();

                    if (! $winner) {
                        throw $e; // not the unique race — a real failure
                    }

                    $partyRole = $winner->is_active ? $winner : tap($winner)->update($attributes);
                }
            }

            $this->profileFor($role);

            $this->unsetRelation('roles');

            return $partyRole;
        });
    }

    /**
     * Never deletes: the party, its other roles, its role PROFILE and all of its
     * journal history stay exactly as they were. Only the role row is flagged
     * inactive — so reactivating later restores the same profile, not a blank one.
     */
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
