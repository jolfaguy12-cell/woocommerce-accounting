<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Support\BillingAddressFormatter;
use Illuminate\Console\Command;

class BackfillCustomerProfileCommand extends Command
{
    protected $signature = 'acc:customers:backfill-profile
        {--dry-run : Only report how many parties would be updated, do not change anything}
        {--json : Machine-readable output}';

    protected $description = 'One-off: populate email/address on existing customer parties from their orders\' already-stored raw payload (the columns did not exist when they were first normalized).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $parties = Party::where('type', 'customer')
            ->where(fn ($q) => $q->whereNull('email')->orWhereNull('address'))
            ->with(['orders' => fn ($q) => $q->latest('order_date')->with('rawOrder')])
            ->get();

        $stats = ['checked' => $parties->count(), 'updated' => 0];

        foreach ($parties as $party) {
            $email = $party->email;
            $address = $party->address;

            foreach ($party->orders as $order) {
                $billing = (array) ($order->rawOrder?->payload['billing'] ?? []);
                if (! $email && filled($billing['email'] ?? null)) {
                    $email = $billing['email'];
                }
                $address ??= BillingAddressFormatter::format($billing);

                if ($email && $address) {
                    break;
                }
            }

            if ($email === $party->email && $address === $party->address) {
                continue;
            }

            $stats['updated']++;

            if (! $dryRun) {
                $party->update(['email' => $email, 'address' => $address]);
            }
        }

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info(($dryRun ? '[dry-run] ' : '')."Updated {$stats['updated']} of {$stats['checked']} customer parties with email/address.");

        return self::SUCCESS;
    }
}
