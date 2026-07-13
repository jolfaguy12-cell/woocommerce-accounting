<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\PhoneNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds parties that MIGHT be the same person and says so. It does not merge,
 * and nothing downstream of it merges either: a shared name, phone, email or
 * Telegram id is evidence for a human, never proof of identity — two real
 * people share a household phone, and one person uses three numbers.
 *
 * A strong identifier (national id, company national id, tax id) is a much
 * better signal than a phone, so matches are ranked, not treated alike.
 */
class PartyDuplicateService
{
    /**
     * Candidate duplicate groups across ALL roles — a supplier and a customer
     * with the same national id is exactly the case the single-role model could
     * never see.
     *
     * @return Collection<int, array{key: string, reason: string, strength: string, parties: Collection<int, Party>}>
     */
    public function candidates(): Collection
    {
        return collect()
            ->concat($this->groupsOn('national_id', 'کد ملی یکسان', 'strong'))
            ->concat($this->groupsOn('company_national_id', 'شناسه ملی یکسان', 'strong'))
            ->concat($this->groupsOn('tax_id', 'شناسه مالیاتی یکسان', 'strong'))
            ->concat($this->groupsOn('normalized_phone', 'شماره تماس یکسان', 'weak'))
            ->concat($this->groupsOn('email', 'ایمیل یکسان', 'weak'))
            ->concat($this->groupsOn('telegram_id', 'شناسه تلگرام یکسان', 'weak'))
            ->values();
    }

    /** @return Collection<int, array{key: string, reason: string, strength: string, parties: Collection<int, Party>}> */
    private function groupsOn(string $column, string $reason, string $strength): Collection
    {
        $values = DB::table('parties')
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        if ($values->isEmpty()) {
            return collect();
        }

        return Party::with('roles')
            ->whereIn($column, $values)
            ->orderBy('id')
            ->get()
            ->groupBy($column)
            ->map(fn (Collection $parties, string $value) => [
                'key' => "{$column}:{$value}",
                'reason' => $reason,
                'strength' => $strength,
                'value' => $value,
                'parties' => $parties,
            ])
            ->values();
    }

    /**
     * Everything that looks like this one party, for the "is this a duplicate?"
     * panel on its own profile. Matches on the party's own identifiers only —
     * never on name alone, which is how one household became three customers.
     *
     * @return Collection<int, array{party: Party, reason: string, strength: string}>
     */
    public function matchesFor(Party $party): Collection
    {
        $checks = [
            ['national_id', $party->national_id, 'کد ملی یکسان', 'strong'],
            ['company_national_id', $party->company_national_id, 'شناسه ملی یکسان', 'strong'],
            ['tax_id', $party->tax_id, 'شناسه مالیاتی یکسان', 'strong'],
            ['normalized_phone', PhoneNormalizer::normalize($party->phone), 'شماره تماس یکسان', 'weak'],
            ['email', $party->email, 'ایمیل یکسان', 'weak'],
            ['telegram_id', $party->telegram_id, 'شناسه تلگرام یکسان', 'weak'],
        ];

        $matches = collect();

        foreach ($checks as [$column, $value, $reason, $strength]) {
            if (blank($value)) {
                continue;
            }

            Party::with('roles')
                ->where($column, $value)
                ->whereKeyNot($party->id)
                ->get()
                ->each(function (Party $match) use ($matches, $reason, $strength) {
                    // Keep the strongest reason we have for each candidate rather
                    // than listing the same party once per matching column.
                    if ($matches->has($match->id) && $matches[$match->id]['strength'] === 'strong') {
                        return;
                    }

                    $matches[$match->id] = ['party' => $match, 'reason' => $reason, 'strength' => $strength];
                });
        }

        return $matches->values();
    }
}
