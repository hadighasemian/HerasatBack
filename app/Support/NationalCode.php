<?php

namespace App\Support;

class NationalCode
{
    /** Normalize: ASCII digits only, left-pad to 10 digits (Excel drops leading zeros). Returns null if not numeric. */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            $value = number_format($value, 0, '', '');
        }
        $value = Persian::toEnglishDigits(trim((string) $value));
        $value = preg_replace('/[\s\-]/', '', $value) ?? '';
        if ($value === '' || ! ctype_digit($value) || strlen($value) > 10) {
            return null;
        }

        return str_pad($value, 10, '0', STR_PAD_LEFT);
    }

    /** Validate the Iranian national code (کد ملی) checksum. */
    public static function isValid(?string $code): bool
    {
        if ($code === null || ! preg_match('/^\d{10}$/', $code)) {
            return false;
        }
        // all-identical digits are invalid
        if (preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $code[$i]) * (10 - $i);
        }
        $remainder = $sum % 11;
        $check = (int) $code[9];

        return ($remainder < 2 && $check === $remainder) || ($remainder >= 2 && $check === 11 - $remainder);
    }

    /** Keyed blind index so the encrypted national code can be unique/looked up without storing plaintext. */
    public static function hash(string $code): string
    {
        return hash_hmac('sha256', 'national_code|'.$code, (string) config('app.key'));
    }

    public static function mask(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

        return substr($code, 0, 3).str_repeat('*', max(0, strlen($code) - 5)).substr($code, -2);
    }
}
