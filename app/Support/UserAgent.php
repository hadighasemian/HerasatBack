<?php

namespace App\Support;

/** Best-effort browser / OS labels from a User-Agent string. Unknown values stay null. */
class UserAgent
{
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);

        return [
            'browser' => $ua === '' ? null : self::browser($ua),
            'os' => $ua === '' ? null : self::os($ua),
        ];
    }

    private static function browser(string $ua): ?string
    {
        $rules = [
            'Edge' => '/Edg(?:e|A|iOS)?\/(\d+)/',
            'Opera' => '/(?:OPR|Opera)\/(\d+)/',
            'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
            'Firefox' => '/Firefox\/(\d+)/',
            'Chrome' => '/(?:Chrome|CriOS)\/(\d+)/',
            'Safari' => '/Version\/(\d+).+Safari\//',
            'Internet Explorer' => '/(?:MSIE |rv:)(\d+)/',
        ];
        foreach ($rules as $name => $pattern) {
            if (preg_match($pattern, $ua, $m)) {
                return $name.' '.$m[1];
            }
        }

        return null;
    }

    private static function os(string $ua): ?string
    {
        if (preg_match('/Android/i', $ua)) {
            return 'Android';
        }
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return 'iOS';
        }
        if (preg_match('/Windows NT|Windows/i', $ua)) {
            return 'Windows';
        }
        if (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            return 'macOS';
        }
        if (preg_match('/Linux/i', $ua)) {
            return 'Linux';
        }

        return null;
    }
}
