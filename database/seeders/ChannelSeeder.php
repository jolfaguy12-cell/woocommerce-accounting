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
            ['وب‌سایت', 'website', 'none', ['completed'], ['checkout', 'website', 'woocommerce_checkout', 'store-api'], null],
            ['ثبت دستی', 'manual', 'none', ['completed'], ['admin', 'manual'], null],
            // Basalam settles payment with the vendor upfront; a bslm-* order is
            // paid the moment it exists, not when WooCommerce's date_paid fires
            // (the hub never sets it for Basalam). Only a rejected/cancelled
            // order was never actually paid out.
            ['باسلام', 'basalam', 'order_commission', ['bslm-completed', 'bslm-shipping'], ['basalam'], [
                'commission_meta_key' => '_basalam_fee_amount',
                // Basalam's own settlement balance for the order — combined with
                // the items total and the commission above to derive a coupon/
                // marketplace discount that never reaches WooCommerce as a line
                // item (see ProfitEngine::channelDiscount()).
                'balance_meta_key' => '_basalam_balance_amount',
                // Basalam's known free-shipping-over-threshold policy for this
                // shop. Only used to label a $0-shipping order's badge with a
                // reason when the order's item total actually clears it — never
                // used to assume shipping is free just because the total does.
                'free_shipping_threshold' => 2_000_000,
                'payment_prepaid_by_channel' => true,
                'payment_prepaid_unless_statuses' => ['bslm-rejected', 'cancelled'],
            ]],
            ['ترب', 'torob', 'wallet_topup', ['completed'], ['torob'], null],
        ];

        foreach ($channels as [$name, $slug, $costModel, $validStatuses, $rawValues, $config]) {
            // No channel-editing UI exists yet — this seeder is the source of
            // truth, so re-running it must sync existing rows, not just create.
            $channel = Channel::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'cost_model' => $costModel,
                'valid_statuses' => $validStatuses,
                'config' => $config,
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
