<?php

namespace Database\Seeders;

use App\Domain\Expenses\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['عمومی و اداری', 'general', '6000'],
            ['حقوق و دستمزد', 'payroll', '6100'],
            ['تبلیغات و بازاریابی', 'ads', '6200'],
            ['حمل و ارسال', 'shipping', '5100'],
            ['ابزار و اشتراک هوش مصنوعی', 'ai-tools', '6000'],
            ['برنامه‌نویسی و توسعه', 'development', '6000'],
        ];

        foreach ($categories as [$name, $slug, $code]) {
            ExpenseCategory::firstOrCreate(['slug' => $slug], [
                'name' => $name, 'account_code' => $code,
            ]);
        }
    }
}
