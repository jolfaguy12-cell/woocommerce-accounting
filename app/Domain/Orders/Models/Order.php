<?php

namespace App\Domain\Orders\Models;

use App\Domain\Accounting\Models\Party;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Sync\Models\RawOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = [
        'order_date' => 'datetime',
        'discount_total' => 'integer',
        'shipping_charged' => 'integer',
        'total' => 'integer',
        'normalized_at' => 'datetime',
        'date_paid' => 'datetime',
    ];

    public function rawOrder(): BelongsTo
    {
        return $this->belongsTo(RawOrder::class);
    }

    public function customerParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_party_id');
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

    public function profit(): HasOne
    {
        return $this->hasOne(OrderProfit::class);
    }

    public function shippingCost(): HasOne
    {
        return $this->hasOne(OrderShippingCost::class);
    }

    public function packagingCost(): HasOne
    {
        return $this->hasOne(OrderPackagingCost::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->latest();
    }

    public function gatewayChecks(): HasMany
    {
        return $this->hasMany(OrderGatewayCheck::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(OrderLabel::class, 'order_label_order');
    }
}
