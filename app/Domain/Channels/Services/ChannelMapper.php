<?php

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Models\ReviewItem;
use Illuminate\Support\Facades\DB;

class ChannelMapper
{
    /** Map a raw source to a channel and reclassify every order carrying it. */
    public function map(ChannelSource $source, Channel $channel, ?int $by = null): int
    {
        return DB::transaction(function () use ($source, $channel, $by) {
            $source->update(['channel_id' => $channel->id, 'status' => 'mapped']);

            $affected = Order::where('channel_source_id', $source->id)
                ->update([
                    'channel_id' => $channel->id,
                    // Profit recalculation picks these up again (M7).
                    'profit_status' => 'pending',
                ]);

            ReviewItem::where('type', 'unknown_source')
                ->where('subject_type', 'channel_source')
                ->where('subject_id', $source->id)
                ->where('status', 'open')
                ->update(['status' => 'resolved', 'resolved_by' => $by, 'resolved_at' => now()]);

            return $affected;
        });
    }
}
