<?php

namespace App\Console\Commands;

use App\Domain\Channels\Models\ChannelSource;
use Illuminate\Console\Command;

class InspectSourcesCommand extends Command
{
    protected $signature = 'acc:inspect:sources {--json : Machine-readable output}';

    protected $description = 'List discovered order sources and their channel mapping status';

    public function handle(): int
    {
        $sources = ChannelSource::with('channel')->orderByDesc('order_count')->get()
            ->map(fn ($s) => [
                'raw_value' => $s->raw_value,
                'status' => $s->status,
                'channel' => $s->channel?->slug,
                'order_count' => $s->order_count,
                'first_seen_at' => $s->first_seen_at?->toIso8601String(),
                'signature' => $s->raw_signature,
            ])->all();

        if ($this->option('json')) {
            $this->line(json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['raw source', 'status', 'channel', 'orders', 'first seen'],
                array_map(fn ($s) => [$s['raw_value'], $s['status'], $s['channel'] ?? '—', $s['order_count'], $s['first_seen_at']], $sources),
            );
        }

        return self::SUCCESS;
    }
}
