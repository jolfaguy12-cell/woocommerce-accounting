<?php

namespace App\Domain\Alerts\Services;

use App\Domain\Accounting\Models\Setting;
use App\Domain\Alerts\Models\AlertDelivery;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramNotifier
{
    /** @throws RuntimeException on any non-success outcome, so the caller (the queued job) can retry. */
    public function send(AlertDelivery $delivery): void
    {
        $token = Setting::getEncrypted('telegram_bot_token');
        $chatId = $delivery->user->telegram_id;

        if (! $token) {
            $this->fail($delivery, 'کلید ربات تلگرام تنظیم نشده است.');

            return;
        }

        if (! $chatId) {
            $delivery->update(['status' => 'skipped_no_telegram_id']);

            return;
        }

        $response = Http::asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $delivery->event->rendered_message,
        ]);

        if (! $response->successful() || ! ($response->json('ok') === true)) {
            $this->fail($delivery, (string) ($response->json('description') ?? $response->status()));

            return;
        }

        $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
    }

    private function fail(AlertDelivery $delivery, string $error): void
    {
        $delivery->update(['status' => 'failed', 'error' => $error]);

        throw new RuntimeException("Telegram send failed for delivery #{$delivery->id}: {$error}");
    }
}
