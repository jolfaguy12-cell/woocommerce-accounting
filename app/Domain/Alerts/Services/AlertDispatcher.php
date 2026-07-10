<?php

namespace App\Domain\Alerts\Services;

use App\Domain\Alerts\Models\AlertEvent;
use App\Domain\Alerts\Models\AlertType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for raising an alert anywhere in the app. Alerts must
 * never break the business flow that triggers them, so failures here are
 * swallowed and logged rather than thrown.
 *
 * Delivery is recorded but not yet sent: there is no Telegram bot wired up
 * yet (see TelegramNotifier). Every eligible recipient still gets an
 * alert_deliveries row so the gap is visible in the admin UI instead of
 * silently dropped, ready to be picked up once sending is implemented.
 */
class AlertDispatcher
{
    public function dispatch(string $code, array $data = [], ?Model $subject = null): ?AlertEvent
    {
        try {
            return $this->doDispatch($code, $data, $subject);
        } catch (\Throwable $e) {
            Log::error('Alert dispatch failed', ['code' => $code, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function doDispatch(string $code, array $data, ?Model $subject): ?AlertEvent
    {
        $type = AlertType::where('code', $code)->first();
        if (! $type) {
            Log::warning("Alert type not found: {$code}");

            return null;
        }

        if (! $type->is_active) {
            return AlertEvent::create([
                'alert_type_id' => $type->id,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'data' => $data,
                'rendered_message' => '',
                'status' => 'skipped_inactive',
                'created_at' => now(),
            ]);
        }

        $message = $this->render($type->message_template, $data);
        $roles = $type->roles;
        $recipients = $roles === [] ? collect() : User::role($roles)->get();

        $event = AlertEvent::create([
            'alert_type_id' => $type->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'data' => $data,
            'rendered_message' => $message,
            'status' => $recipients->isEmpty() ? 'skipped_no_recipients' : 'dispatched',
            'created_at' => now(),
        ]);

        foreach ($recipients as $user) {
            $event->deliveries()->create([
                'user_id' => $user->id,
                'channel' => 'telegram',
                'status' => $user->telegram_id ? 'pending' : 'skipped_no_telegram_id',
                'created_at' => now(),
            ]);
        }

        return $event;
    }

    private function render(string $template, array $data): string
    {
        $replacements = [];
        foreach ($data as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
