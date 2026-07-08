<?php

namespace App\Domain\Orders\Models;

use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Sync\Models\RawOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = [
        'order_date' => 'datetime',
        'discount_total' => 'integer',
        'shipping_charged' => 'integer',
        'total' => 'integer',
        'normalized_at' => 'datetime',
    ];

    public function rawOrder(): BelongsTo
    {
        return $this->belongsTo(RawOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function channelSource(): BelongsTo
    {
        return $this->belongsTo(ChannelSource::class);
    }
}
