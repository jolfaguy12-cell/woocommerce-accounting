<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Support\PartyIdentityBackfill;
use Illuminate\Console\Command;

class BackfillPartyRolesCommand extends Command
{
    protected $signature = 'parties:backfill-roles {--json : Machine-readable output}';

    protected $description = 'Give every party a party_roles row for its legacy `type`, and fill normalized_phone. Idempotent — re-run after deploy, since order sync keeps creating parties.';

    public function handle(): int
    {
        $stats = [
            'roles_created' => PartyIdentityBackfill::roles(),
            'phones_normalized' => PartyIdentityBackfill::normalizedPhones(),
        ];

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info("Created {$stats['roles_created']} party role(s); normalized {$stats['phones_normalized']} phone(s).");

        return self::SUCCESS;
    }
}
