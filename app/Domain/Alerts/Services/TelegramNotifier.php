<?php

namespace App\Domain\Alerts\Services;

use App\Domain\Alerts\Models\AlertDelivery;

/**
 * TODO: not wired up yet. Once a Telegram bot token exists, implement the
 * real Bot API call here (POST https://api.telegram.org/bot{token}/sendMessage
 * with chat_id = $delivery->user->telegram_id and text = $delivery->event->rendered_message),
 * mark the delivery sent/failed accordingly, and add a queued job (or a
 * scheduled command, matching the acc:sync:* convention in routes/console.php)
 * that scans alert_deliveries where status = 'pending' and calls send() on each.
 * Nothing currently invokes this class — deliveries just accumulate as 'pending'.
 */
class TelegramNotifier
{
    public function send(AlertDelivery $delivery): void
    {
        // Intentionally not implemented — see class docblock TODO.
    }
}
