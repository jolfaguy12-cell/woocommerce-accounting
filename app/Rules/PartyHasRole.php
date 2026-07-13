<?php

namespace App\Rules;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\PartyRoleType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Replaces `Rule::exists('parties', 'id')->where('type', 'supplier')`, which was
 * the only place in the app that enforced "this party must be a supplier" — and
 * which stops meaning anything once a party can hold several roles.
 */
class PartyHasRole implements ValidationRule
{
    public function __construct(private readonly PartyRoleType $role) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return; // presence is `required`/`nullable`'s job, not ours
        }

        if (! Party::withRole($this->role)->whereKey($value)->exists()) {
            $fail("طرف حساب انتخاب‌شده {$this->role->label()} نیست.");
        }
    }
}
