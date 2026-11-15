<?php

namespace App\Support;

class Phone
{
    public static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value)) {
            $value = number_format($value, 0, '', '');
        }
        $digits = preg_replace('/\D/', '', Persian::toEnglishDigits((string) $value)) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }
        // Excel stored the number without its leading zero
        if (strlen($digits) === 10 && $digits[0] !== '0') {
            $digits = '0'.$digits;
        }

        return strlen($digits) >= 5 && strlen($digits) <= 15 ? $digits : null;
    }

    public static function mask(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $len = strlen($phone);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($phone, 0, 4).str_repeat('*', max(0, $len - 7)).substr($phone, -3);
    }
}
