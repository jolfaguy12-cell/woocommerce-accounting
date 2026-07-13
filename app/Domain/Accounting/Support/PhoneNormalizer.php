<?php

namespace App\Domain\Accounting\Support;

/**
 * Canonical form of an Iranian phone number. Lifted verbatim out of
 * CustomerResolver (which still calls it) so that party identity, duplicate
 * detection and order sync all decide "is this the same number?" the same way
 * — the moment two of them disagree, we start minting duplicate parties again.
 */
class PhoneNormalizer
{
    /**
     * The hub's billing phone shows up in several equivalent forms for the
     * same real number (+989121234567 / 00989121234567 / 9121234567 /
     * 09121234567) — normalized to a single canonical form so format drift
     * alone never creates a duplicate customer.
     */
    public static function normalize(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        // Billing phones sometimes arrive in Persian/Arabic-Indic digits —
        // \D wouldn't touch those, so left alone they'd strip to nothing below.
        $ascii = strtr($phone, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $digits = preg_replace('/\D/', '', $ascii) ?? '';

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '0'.$digits;
        }

        // Not a single recognizable mobile number (e.g. two numbers pasted
        // into one field) — don't guess, fall back to the original value.
        if (strlen($digits) !== 11) {
            return trim($phone) !== '' ? trim($phone) : null;
        }

        return $digits;
    }
}
