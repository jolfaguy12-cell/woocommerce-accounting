<?php

namespace App\Domain\Alerts\Services;

use App\Domain\Alerts\Models\AlertEvent;
use App\Domain\Alerts\Models\AlertType;
use App\Jobs\SendTelegramAlertJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for raising an alert anywhere in the app. Alerts must
 * never break the business flow that triggers them, so failures here are
 * swallowed and logged rather than thrown.
 *
 * Every eligible recipient gets two delivery rows: `telegram` (queued for
 * real sending via SendTelegramAlertJob/TelegramNotifier) and `in_app`
 * (immediately visible in the dashboard notification bell). resolve() is
 * the symmetric close — called once the condition that raised the alert is
 * fixed, mirroring ChannelMapper's ReviewItem auto-resolve pattern.
 */
class AlertDispatcher
{
    public function dispatch(string $code, array $data = [], ?Model $subject = null, ?string $url = null): ?AlertEvent
    {
        try {
            return $this->doDispatch($code, $data, $subject, $url);
        } catch (\Throwable $e) {
            Log::error('Alert dispatch failed', ['code' => $code, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Closes every still-open delivery for this code+subject — the alert's underlying condition is fixed. */
    public function resolve(string $code, Model $subject): void
    {
        try {
            AlertEvent::query()
                ->whereHas('alertType', fn ($q) => $q->where('code', $code))
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->whereHas('deliveries', fn ($q) => $q->whereNull('resolved_at'))
                ->get()
                ->each(fn (AlertEvent $event) => $event->deliveries()->whereNull('resolved_at')->update(['resolved_at' => now()]));
        } catch (\Throwable $e) {
            Log::error('Alert resolve failed', ['code' => $code, 'error' => $e->getMessage()]);
        }
    }

    private function doDispatch(string $code, array $data, ?Model $subject, ?string $url): ?AlertEvent
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
                'url' => $url,
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
            'url' => $url,
            'status' => $recipients->isEmpty() ? 'skipped_no_recipients' : 'dispatched',
            'created_at' => now(),
        ]);

        foreach ($recipients as $user) {
            $delivery = $event->deliveries()->create([
                'user_id' => $user->id,
                'channel' => 'telegram',
                'status' => $user->telegram_id ? 'pending' : 'skipped_no_telegram_id',
                'created_at' => now(),
            ]);

            $event->deliveries()->create([
                'user_id' => $user->id,
                'channel' => 'in_app',
                'status' => 'sent',
                'created_at' => now(),
            ]);

            if ($delivery->status === 'pending') {
                // Isolated from the alert/delivery creation above: on the sync queue
                // driver (or a job that fails immediately) this runs inline, and a
                // Telegram-side failure must never make it look like the alert
                // itself (already safely persisted) failed to dispatch.
                try {
                    SendTelegramAlertJob::dispatch($delivery->id);
                } catch (\Throwable $e) {
                    Log::error('Telegram alert job dispatch failed', ['delivery_id' => $delivery->id, 'error' => $e->getMessage()]);
                }
            }
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
