<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Support\PartyIdentityBackfill;
use Illuminate\Console\Command;

class SyncPartyProfilesCommand extends Command
{
    protected $signature = 'parties:sync-profiles {--json : Machine-readable output}';

    protected $description = 'Create any missing customer/supplier role profiles from the legacy parties columns. Idempotent — only inserts what is missing, never overwrites a profile the UI has since edited.';

    public function handle(): int
    {
        $stats = PartyIdentityBackfill::profiles();

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info("Created {$stats['customer_profiles']} customer profile(s), {$stats['supplier_profiles']} supplier profile(s), {$stats['bank_accounts']} counterparty bank account(s).");

        return self::SUCCESS;
    }
}
