<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Setting;
use App\Domain\Costing\Models\PackagingCostTier;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PackagingCostController extends Controller
{
    public function index(): View
    {
        return view('pages.warehouse.packaging-cost', [
            'title' => 'هزینه بسته‌بندی',
            'tiers' => PackagingCostTier::orderBy('min_weight_grams')->get(),
            'defaults' => [
                'default_packaging_cost' => (int) Setting::get('default_packaging_cost', 12000),
                'default_product_weight_grams' => (int) Setting::get('default_product_weight_grams', 150),
                'default_packaging_weight_grams' => (int) Setting::get('default_packaging_weight_grams', 100),
            ],
        ]);
    }

    public function updateDefaults(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'default_packaging_cost' => 'required|integer|min:0',
            'default_product_weight_grams' => 'required|integer|min:0',
            'default_packaging_weight_grams' => 'required|integer|min:0',
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        return back()->with('success', 'مقادیر پیش‌فرض ذخیره شد.');
    }

    public function storeTier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'min_weight_grams' => 'required|integer|min:0|unique:packaging_cost_tiers,min_weight_grams',
            'cost' => 'required|integer|min:0',
        ]);

        PackagingCostTier::create($data);

        return back()->with('success', 'پله جدید اضافه شد.');
    }

    public function updateTier(Request $request, PackagingCostTier $tier): RedirectResponse
    {
        $data = $request->validate([
            'min_weight_grams' => 'required|integer|min:0|unique:packaging_cost_tiers,min_weight_grams,'.$tier->id,
            'cost' => 'required|integer|min:0',
        ]);

        $tier->update($data);

        return back()->with('success', 'پله ویرایش شد.');
    }

    public function destroyTier(PackagingCostTier $tier): RedirectResponse
    {
        $tier->delete();

        return back()->with('success', 'پله حذف شد.');
    }
}
