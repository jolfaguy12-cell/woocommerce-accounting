<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Channels\Services\ChannelMapper;
use App\Domain\Sync\Models\ReviewItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('review', [
            'items' => ReviewItem::with('subject')->where('status', 'open')->latest()->limit(200)->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'payload' => $item->payload,
                    'subject_type' => $item->subject_type,
                    'subject_id' => $item->subject_id,
                    'source' => $item->subject_type === 'channel_source' ? [
                        'id' => $item->subject?->id,
                        'raw_value' => $item->subject?->raw_value,
                        'order_count' => $item->subject?->order_count,
                    ] : null,
                    'created_at' => $item->created_at->toIso8601String(),
                ]),
            'channels' => Channel::where('is_active', true)->get(['id', 'name', 'slug', 'cost_model']),
        ]);
    }

    public function resolve(Request $request, ReviewItem $item): RedirectResponse
    {
        $request->validate(['action' => 'required|in:resolved,dismissed']);

        $item->update([
            'status' => $request->string('action'),
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'آیتم بازبینی بسته شد.');
    }

    /** Map an unknown raw source to an existing or brand-new channel. */
    public function mapSource(Request $request, ChannelSource $source, ChannelMapper $mapper): RedirectResponse
    {
        $data = $request->validate([
            'channel_id' => 'nullable|exists:channels,id',
            'new_channel_name' => 'nullable|string|max:100|required_without:channel_id',
            'new_channel_cost_model' => 'nullable|in:none,manual_period,wallet_topup,order_commission,api_enriched',
        ]);

        $channel = isset($data['channel_id'])
            ? Channel::findOrFail($data['channel_id'])
            : Channel::create([
                'name' => $data['new_channel_name'],
                'slug' => Str::slug($data['new_channel_name']).'-'.$source->id,
                'cost_model' => $data['new_channel_cost_model'] ?? 'none',
                'valid_statuses' => ['completed'],
            ]);

        $affected = $mapper->map($source, $channel, $request->user()->id);

        return back()->with('success', "منبع «{$source->raw_value}» به کانال {$channel->name} متصل شد ({$affected} سفارش بازطبقه‌بندی شد).");
    }
}
