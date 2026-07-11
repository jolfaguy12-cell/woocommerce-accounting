<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A manually-created WooCommerce order gets date_paid stamped the moment
     * staff save it as processing/completed (for stock-keeping reasons),
     * regardless of whether any money actually changed hands — see
     * OrderNormalizer::paymentStatus(). Existing rows need this flag too,
     * not just future acc:sync:order/ChannelSeeder runs.
     */
    public function up(): void
    {
        $channel = DB::table('channels')->where('slug', 'manual')->first();
        if (! $channel) {
            return;
        }

        $config = $channel->config ? json_decode($channel->config, true) : [];
        $config['payment_never_prepaid_by_channel'] = true;

        DB::table('channels')->where('slug', 'manual')->update([
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $channel = DB::table('channels')->where('slug', 'manual')->first();
        if (! $channel) {
            return;
        }

        $config = $channel->config ? json_decode($channel->config, true) : [];
        unset($config['payment_never_prepaid_by_channel']);

        DB::table('channels')->where('slug', 'manual')->update([
            'config' => $config === [] ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
