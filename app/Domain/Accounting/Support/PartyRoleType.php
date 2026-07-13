<?php

namespace App\Domain\Accounting\Support;

use InvalidArgumentException;

/**
 * The allowed values of party_roles.role. Deliberately a domain enum rather
 * than a database enum, so adding a role is a code change, not a migration.
 *
 * Lender and borrower are NOT roles: whether a party lent to us or borrowed
 * from us is a property of the loan contract (its direction), not a permanent
 * property of the person — the same supplier can do both, at different times.
 */
enum PartyRoleType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Employee = 'employee';
    case Partner = 'partner';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'مشتری',
            self::Supplier => 'تأمین‌کننده',
            self::Employee => 'کارمند',
            self::Partner => 'شریک',
            self::Other => 'سایر طرف حساب‌ها',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Accepts either the enum or its string value — call sites migrating off parties.type still pass strings. */
    public static function coerce(string|self $role): self
    {
        if ($role instanceof self) {
            return $role;
        }

        return self::tryFrom($role) ?? throw new InvalidArgumentException("Unknown party role [{$role}].");
    }
}
