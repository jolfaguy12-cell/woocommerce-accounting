<?php

namespace Database\Seeders;

use App\Domain\Alerts\Models\AlertType;
use Illuminate\Database\Seeder;

class AlertTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code' => 'zibal_gateway_mismatch',
                'name' => 'ناهماهنگی وضعیت درگاه پرداخت',
                'description' => 'سفارش در ووکامرس پرداخت‌شده علامت خورده اما زیبال تراکنش را ناموفق گزارش کرده است.',
                'message_template' => '⚠️ ناهماهنگی درگاه پرداخت: سفارش #{order_id} در ووکامرس "{order_status}" است اما زیبال تراکنش را "{gateway_status}" گزارش کرده. مبلغ: {amount} تومان.',
                'roles' => ['admin', 'accountant'],
            ],
            [
                'code' => 'zibal_new_bank_account_detected',
                'name' => 'شناسایی حساب بانکی مقصد جدید',
                'description' => 'در فایل واریزی‌های زیبال، واریزی به حسابی غیر از حساب‌های ثبت‌شده قبلی شناسایی شد.',
                'message_template' => 'ℹ️ حساب بانکی مقصد جدید شناسایی شد: {iban} ({holder_name}) و به‌صورت خودکار ثبت شد. لطفاً بررسی کنید.',
                'roles' => ['admin'],
            ],
        ];

        foreach ($types as $data) {
            $roles = $data['roles'];
            unset($data['roles']);

            $type = AlertType::firstOrCreate(['code' => $data['code']], $data);
            if ($type->roles === []) {
                $type->syncRoles($roles);
            }
        }
    }
}
