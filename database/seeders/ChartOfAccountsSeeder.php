<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\Account;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /** Base system chart; amounts are Toman. Extend via UI, never delete system rows. */
    public function run(): void
    {
        $accounts = [
            // assets
            ['1000', 'صندوق', 'asset'],
            ['1100', 'بانک', 'asset'],
            ['1150', 'تسویه در جریان زیبال (واسط)', 'asset'],
            ['1200', 'حساب‌های دریافتنی', 'asset'],
            ['1250', 'اسناد دریافتنی (چک)', 'asset'],
            ['1300', 'موجودی کالا / خرید', 'asset'],
            ['1400', 'پیش‌پرداخت‌ها و مساعده کارکنان', 'asset'],
            ['1450', 'پیش‌پرداخت به تأمین‌کننده', 'asset'],
            ['1500', 'دارایی‌های ثابت', 'asset'],
            ['1600', 'تسهیلات اعطایی (وام پرداختی)', 'asset'],
            // liabilities
            ['2000', 'حساب‌های پرداختنی', 'liability'],
            ['2100', 'اسناد پرداختنی (چک)', 'liability'],
            ['2200', 'تسهیلات و وام‌ها', 'liability'],
            ['2300', 'حقوق پرداختنی', 'liability'],
            ['2350', 'حساب جاری کارمند', 'liability'],
            ['2400', 'پیش‌دریافت و اعتبار مشتریان', 'liability'],
            ['2500', 'سود سهم شرکا پرداختنی', 'liability'],
            ['2600', 'حساب جاری شرکا', 'liability'],
            // equity
            ['3000', 'سرمایه / تراز افتتاحیه', 'equity'],
            ['3100', 'برداشت شرکا', 'equity'],
            ['3200', 'سود (زیان) انباشته', 'equity'],
            // revenue
            ['4000', 'درآمد فروش', 'revenue'],
            ['4100', 'درآمد حمل دریافتی از مشتری', 'revenue'],
            ['4200', 'سود و کارمزد دریافتی', 'revenue'],
            ['4900', 'سایر درآمدها', 'revenue'],
            // expenses
            ['5000', 'بهای تمام‌شده کالای فروش‌رفته', 'expense'],
            ['5100', 'هزینه حمل و ارسال', 'expense'],
            ['5200', 'کارمزد کانال فروش', 'expense'],
            ['5300', 'کارمزد درگاه پرداخت', 'expense'],
            ['6000', 'هزینه‌های عملیاتی', 'expense'],
            ['6100', 'حقوق و دستمزد', 'expense'],
            ['6200', 'هزینه تبلیغات و بازاریابی', 'expense'],
            ['6300', 'هزینه‌های مالی و بهره', 'expense'],
            ['6350', 'کارمزد بانکی', 'expense'],
            ['6370', 'جریمه دیرکرد', 'expense'],
            ['6400', 'مطالبات سوخت‌شده', 'expense'],
            ['9999', 'حساب تعدیل رند کردن', 'expense'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            Account::firstOrCreate(['code' => $code], [
                'name' => $name,
                'type' => $type,
                'is_system' => true,
            ]);
        }
    }
}
