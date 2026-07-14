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
 *   ops.approval_threshold   (int Toman, default NULL = approval DISABLED)
 *       Off unless an admin turns it on. While it is unset — the default, and the
 *       normal state of this system — an authorised user posts directly and no
 *       approval step exists at all.
 *
 *       Set it, and an operation at or above the threshold is parked as
 *       `pending_approval` until someone with the approve role approves it. That
 *       someone may be its own creator (see canApprove): the threshold is a
 *       "stop and look again" prompt, not a two-person rule. A business that
 *       genuinely wants four eyes gets them by giving the approve role only to
 *       people who are not the ones entering operations.
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
     * May this user approve this operation?
     *
     * Role only. The creator MAY approve their own operation — this is a small
     * business, frequently a single bookkeeper, and a rule that requires a second
     * human being to exist does not make the books safer when there is no second
     * human being: it makes the operation impossible to post, and the work moves
     * somewhere outside the system where nothing is recorded at all.
     *
     * What actually protects the ledger is unchanged and does not depend on there
     * being two people: every action is attributed and activity-logged, posted
     * entries are immutable, and a correction is a reversal that leaves both
     * entries standing. A shop that DOES have two people can still get four-eyes
     * by setting `ops.approval_threshold` and giving the approve role to someone
     * else — but nothing forces it by default.
     */
    public function canApprove(User $user, Model $operation): bool
    {
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
