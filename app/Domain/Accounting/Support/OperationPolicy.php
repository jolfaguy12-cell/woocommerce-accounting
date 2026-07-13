<?php

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may create, approve and reverse a financial operation, and when a second
 * pair of eyes is required. Configuration lives in `settings`, so tightening the
 * controls is an admin action, not a deploy.
 *
 * Keys and their defaults:
 *
 *   ops.approval_threshold   (int Toman, default NULL = approval disabled)
 *       An operation whose amount is >= the threshold cannot be posted by the
 *       person who created it; a second, authorised user must approve it. Below
 *       the threshold — and always, while the setting is unset — an authorised
 *       creator posts directly.
 *
 *       ⚠ Setting a threshold on a system with only ONE approver deadlocks every
 *       operation at or above it: the creator is barred from approving their own
 *       work and nobody else can. Add a second approver (or widen
 *       ops.roles.approve) before setting this.
 *
 *   ops.negative_balance_mode ('block'|'warn'|'allow', default 'warn')
 *       What to do when an operation would drive a bank/cash account below zero.
 *
 *   ops.roles.create   (default ['admin','accountant'])
 *   ops.roles.approve  (default ['admin'])
 *   ops.roles.reverse  (default ['admin'])
 */
class OperationPolicy
{
    public const APPROVAL_THRESHOLD = 'ops.approval_threshold';

    public const NEGATIVE_BALANCE_MODE = 'ops.negative_balance_mode';

    public const ROLES_CREATE = 'ops.roles.create';

    public const ROLES_APPROVE = 'ops.roles.approve';

    public const ROLES_REVERSE = 'ops.roles.reverse';

    public const MODE_BLOCK = 'block';

    public const MODE_WARN = 'warn';

    public const MODE_ALLOW = 'allow';

    /** Approval is off unless an admin sets a threshold. */
    public function approvalThreshold(): ?int
    {
        $raw = Setting::get(self::APPROVAL_THRESHOLD);

        return ($raw === null || $raw === '') ? null : (int) $raw;
    }

    public function requiresApproval(int $amount): bool
    {
        $threshold = $this->approvalThreshold();

        return $threshold !== null && $amount >= $threshold;
    }

    public function negativeBalanceMode(): string
    {
        $mode = (string) Setting::get(self::NEGATIVE_BALANCE_MODE, self::MODE_WARN);

        return in_array($mode, [self::MODE_BLOCK, self::MODE_WARN, self::MODE_ALLOW], true)
            ? $mode
            : self::MODE_WARN;
    }

    public function canCreate(User $user): bool
    {
        return $user->hasAnyRole($this->roles(self::ROLES_CREATE, ['admin', 'accountant']));
    }

    /**
     * Approval is a control, not a formality: the creator can never be the
     * approver, however senior they are. That is the whole point of a threshold.
     */
    public function canApprove(User $user, Model $operation): bool
    {
        if ((int) $operation->created_by === (int) $user->id) {
            return false;
        }

        return $user->hasAnyRole($this->roles(self::ROLES_APPROVE, ['admin']));
    }

    public function canReverse(User $user): bool
    {
        return $user->hasAnyRole($this->roles(self::ROLES_REVERSE, ['admin']));
    }

    /** @return list<string> */
    private function roles(string $key, array $default): array
    {
        $roles = Setting::get($key, $default);

        return is_array($roles) && $roles !== [] ? array_values($roles) : $default;
    }
}
