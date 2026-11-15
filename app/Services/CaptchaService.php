<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Self-hosted image captcha (no third-party services). The answer is kept server-side in the cache
 * (only its keyed hash), bound to a random id and valid for a single verification attempt.
 */
class CaptchaService
{
    public function create(): array
    {
        $cfg = config('herasat.captcha');
        $code = $this->generateCode();
        $id = Str::random(40);
        Cache::put($this->key($id), $this->hashAnswer($code), $cfg['ttl_seconds']);

        return ['id' => $id, 'image' => 'data:image/png;base64,'.base64_encode($this->render($code)), 'expires_in' => $cfg['ttl_seconds']];
    }

    /** Verify and consume (single-use, even when wrong). */
    public function verify(?string $id, ?string $answer): bool
    {
        if (! is_string($id) || ! is_string($answer) || strlen($id) !== 40 || ! ctype_alnum($id)) {
            return false;
        }
        $stored = Cache::pull($this->key($id));
        if (! is_string($stored)) {
            return false;
        }

        return hash_equals($stored, $this->hashAnswer($answer));
    }

    protected function generateCode(): string
    {
        $cfg = config('herasat.captcha');
        $alphabet = $cfg['alphabet'];
        $code = '';
        for ($i = 0; $i < $cfg['length']; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function key(string $id): string
    {
        return 'captcha:'.$id;
    }

    private function hashAnswer(string $answer): string
    {
        $normalized = strtoupper(trim(\App\Support\Persian::toEnglishDigits($answer)));

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    private function render(string $code): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The PHP GD extension is required for captcha images.');
        }
        $w = 180;
        $h = 60;
        $img = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($img, random_int(235, 255), random_int(235, 255), random_int(235, 255));
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        // background noise
        for ($i = 0; $i < 400; $i++) {
            $c = imagecolorallocate($img, random_int(150, 220), random_int(150, 220), random_int(150, 220));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }
        for ($i = 0; $i < 6; $i++) {
            $c = imagecolorallocate($img, random_int(100, 200), random_int(100, 200), random_int(100, 200));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }

        $font = resource_path('fonts/captcha.ttf');
        $hasTtf = function_exists('imagettftext') && is_file($font);
        $x = 12;
        foreach (str_split($code) as $ch) {
            $color = imagecolorallocate($img, random_int(0, 90), random_int(0, 90), random_int(0, 120));
            if ($hasTtf) {
                imagettftext($img, random_int(22, 28), random_int(-25, 25), $x, random_int(38, 48), $color, $font, $ch);
                $x += random_int(28, 33);
            } else {
                // Built-in font fallback: draw each char on a small canvas, scale it up and paste with jitter
                $tile = imagecreatetruecolor(10, 16);
                imagefill($tile, 0, 0, $bg);
                imagestring($tile, 5, 1, 0, $ch, $color);
                imagecopyresampled($img, $tile, $x, random_int(5, 15), 0, 0, random_int(22, 28), random_int(34, 40), 10, 16);
                imagedestroy($tile);
                $x += random_int(28, 32);
            }
        }
        // foreground arcs crossing the text
        for ($i = 0; $i < 3; $i++) {
            $c = imagecolorallocate($img, random_int(0, 120), random_int(0, 120), random_int(0, 120));
            imagearc($img, random_int(0, $w), random_int(0, $h), random_int(80, 200), random_int(30, 80), random_int(0, 360), random_int(0, 360), $c);
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }
}
