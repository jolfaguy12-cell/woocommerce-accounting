<?php

namespace App\Console\Commands;

use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Costing\Services\CostResolver;
use App\Domain\Products\Models\ProductMirror;
use Illuminate\Console\Command;

class MissingCostsCommand extends Command
{
    protected $signature = 'acc:costs:missing {--json : Machine-readable output}';

    protected $description = 'List products without Cost Mapping and mapped items without any purchase cost';

    public function handle(CostResolver $resolver): int
    {
        $sellable = ProductMirror::whereIn('type', ['simple', 'variation'])->get();
        $mappings = ProductCostMapping::where('status', 'mapped')->pluck('product_mirror_id');

        $unmapped = $sellable->whereNotIn('id', $mappings)->values();
        $missingCost = $sellable->whereIn('id', $mappings)
            ->filter(fn ($p) => $resolver->resolveFor($p) === null)->values();

        $result = [
            'unmapped' => $unmapped->map(fn ($p) => ['hub_product_id' => $p->hub_product_id, 'name' => $p->name])->all(),
            'missing_cost' => $missingCost->map(fn ($p) => ['hub_product_id' => $p->hub_product_id, 'name' => $p->name])->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->warn(count($result['unmapped']).' product(s) without Cost Mapping');
            foreach ($result['unmapped'] as $p) {
                $this->line("  - [{$p['hub_product_id']}] {$p['name']}");
            }
            $this->warn(count($result['missing_cost']).' mapped product(s) without any cost');
            foreach ($result['missing_cost'] as $p) {
                $this->line("  - [{$p['hub_product_id']}] {$p['name']}");
            }
        }

        return self::SUCCESS;
    }
}
