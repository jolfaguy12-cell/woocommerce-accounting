<?php

namespace App\Support\Design;

use App\Domain\Accounting\Models\AccountTransaction;
use App\Domain\Accounting\Models\AccountTransfer;
use Illuminate\Database\Eloquent\Model;

/**
 * The page a ledger line came from, if it has one.
 *
 * A journal entry knows its source (`source_type`/`source_id`), but a ledger view
 * only has the line. This turns the source back into a link, so a reader looking
 * at "انتقال وجه از صندوق به بانک ملت" in the bank ledger can open the operation
 * that caused it — from EITHER side of the transfer, since both sides are lines
 * on the same entry.
 *
 * Unknown or absent sources return null (the description renders as plain text),
 * so a new journal source can never break an existing ledger page.
 */
class LedgerSourceLink
{
    public static function for(?Model $source): ?string
    {
        return match (true) {
            $source instanceof AccountTransfer => route('financial-operations.transfers.show', $source),
            $source instanceof AccountTransaction => route('financial-operations.transactions.show', $source),
            default => null,
        };
    }
}
