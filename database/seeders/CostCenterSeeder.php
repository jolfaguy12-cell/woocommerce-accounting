<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\CostCenter;
use Illuminate\Database\Seeder;

class CostCenterSeeder extends Seeder
{
    public function run(): void
    {
        $centers = [
            'warehouse' => 'انبار',
            'logistics' => 'لجستیک و ارسال',
            'development' => 'برنامه‌نویسی و توسعه',
            'ai-tools' => 'ابزارهای هوش مصنوعی',
            'marketing' => 'بازاریابی',
            'management' => 'مدیریت',
            'finance' => 'مالی و حسابداری',
            'hr' => 'منابع انسانی',
            'purchasing' => 'تأمین و خرید',
        ];

        foreach ($centers as $slug => $name) {
            CostCenter::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
