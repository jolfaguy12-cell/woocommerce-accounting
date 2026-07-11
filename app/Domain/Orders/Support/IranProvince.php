<?php

namespace App\Domain\Orders\Support;

/**
 * WooCommerce's Iran billing "state" field is unreliable: direct-checkout
 * orders store WooCommerce's own standard 3-letter province code, but some
 * channels (e.g. Basalam) sync their own internal numeric city id into the
 * same field instead. Only the standard codes are a documented, fixed list
 * we can decode with confidence — anything else falls back to "already
 * readable text" (pass through) or null (never guess a wrong province).
 */
class IranProvince
{
    /** WooCommerce's standard Iran state code => Persian province name. */
    private const CODES = [
        'THR' => 'تهران', 'ALB' => 'البرز', 'ADL' => 'اردبیل', 'ISF' => 'اصفهان',
        'ILM' => 'ایلام', 'BHR' => 'بوشهر', 'CHB' => 'چهارمحال و بختیاری',
        'SKH' => 'خراسان جنوبی', 'KHK' => 'خراسان شمالی', 'RKV' => 'خراسان رضوی',
        'KHZ' => 'خوزستان', 'ZJN' => 'زنجان', 'SMN' => 'سمنان',
        'SBN' => 'سیستان و بلوچستان', 'FRS' => 'فارس', 'QZV' => 'قزوین', 'GZN' => 'قزوین',
        'QHM' => 'قم', 'KRD' => 'کردستان', 'KRN' => 'کرمان', 'KRH' => 'کرمانشاه',
        'KBD' => 'کهگیلویه و بویراحمد', 'GLS' => 'گلستان', 'GIL' => 'گیلان',
        'LRS' => 'لرستان', 'MZN' => 'مازندران', 'MKZ' => 'مرکزی', 'HRZ' => 'هرمزگان',
        'HDN' => 'همدان', 'YZD' => 'یزد', 'EAZ' => 'آذربایجان شرقی', 'WAZ' => 'آذربایجان غربی',
    ];

    /** Null when the raw state value can't be confidently resolved to a real province name. */
    public static function resolve(?string $rawState): ?string
    {
        $value = trim((string) $rawState);

        if ($value === '') {
            return null;
        }

        if (isset(self::CODES[strtoupper($value)])) {
            return self::CODES[strtoupper($value)];
        }

        // A purely numeric or short Latin code we don't recognize (e.g. a
        // channel's own internal city id) — not a province name, don't show it.
        if (preg_match('/^[0-9A-Za-z]+$/', $value) === 1) {
            return null;
        }

        return $value; // already human-readable Persian text
    }
}
