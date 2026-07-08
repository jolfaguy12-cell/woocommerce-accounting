<?php

namespace Database\Seeders;

use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelSource;
use Illuminate\Database\Seeder;

/**
 * Initial channel registry DATA (editable in the UI) — not a hard-coded list.
 * New sources create unknown channel_sources at runtime and go to review.
 */
class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['وب‌سایت', 'website', 'none', ['completed'], ['checkout', 'website', 'woocommerce_checkout', 'store-api']],
            ['ثبت دستی', 'manual', 'none', ['completed'], ['admin', 'manual']],
            ['باسلام', 'basalam', 'order_commission', ['completed', 'bslm-sent', 'bslm-delivered'], ['basalam']],
            ['ترب', 'torob', 'wallet_topup', ['completed'], ['torob']],
        ];

        foreach ($channels as [$name, $slug, $costModel, $validStatuses, $rawValues]) {
            $channel = Channel::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'cost_model' => $costModel,
                'valid_statuses' => $validStatuses,
                'config' => $slug === 'basalam' ? ['commission_meta_key' => '_basalam_fee_amount'] : null,
            ]);

            foreach ($rawValues as $raw) {
                ChannelSource::firstOrCreate(['raw_value' => $raw], [
                    'channel_id' => $channel->id,
                    'status' => 'mapped',
                    'first_seen_at' => now(),
                ]);
            }
        }
    }
}
