<?php

namespace App\Support;

class Persian
{
    /** Convert Persian/Arabic digits to ASCII digits. */
    public static function toEnglishDigits(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /** Normalize Arabic letters to Persian, collapse whitespace, strip control/zero-width chars at the edges. */
    public static function normalizeText(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = strtr($value, ['ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', "\u{00A0}" => ' ']);
        $value = preg_replace('/[\x{200B}\x{200E}\x{200F}\x{FEFF}]/u', '', $value) ?? $value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /** Header normalization for importer: also removes ZWNJ and all spaces. */
    public static function normalizeHeader(?string $value): string
    {
        $value = self::normalizeText(self::toEnglishDigits($value));
        $value = str_replace("\u{200C}", '', $value);

        return mb_strtolower(preg_replace('/[\s_\-]+/u', '', $value) ?? $value);
    }
}
