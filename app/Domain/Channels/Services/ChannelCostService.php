<?php

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelCost;
use App\Domain\Orders\Models\OrderProfit;

class ChannelCostService
{
    /** Total cost of a channel in one Jalali period, per its configured cost model. */
    public function periodCost(Channel $channel, string $jalaliPeriod): int
    {
        return match ($channel->cost_model) {
            'wallet_topup' => (int) ChannelCost::where('channel_id', $channel->id)
                ->where('jalali_period', $jalaliPeriod)->where('type', 'topup')->sum('amount'),
            'manual_period' => (int) ChannelCost::where('channel_id', $channel->id)
                ->where('jalali_period', $jalaliPeriod)->where('type', 'manual')->sum('amount'),
            'order_commission' => (int) OrderProfit::whereIn('status', ['final', 'provisional'])
                ->whereHas('order', fn ($q) => $q->where('channel_id', $channel->id)
                    ->where('jalali_period', $jalaliPeriod))
                ->sum('channel_fee'),
            default => 0, // none / api_enriched (enrichment lands later, hub stays primary)
        };
    }
}
