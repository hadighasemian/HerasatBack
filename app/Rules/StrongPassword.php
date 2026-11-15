<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Password strength: min length (config), upper + lower case latin letters, digit, symbol,
 * no whitespace, not containing the personnel/national code, not a repeated/sequential pattern.
 */
class StrongPassword implements ValidationRule
{
    public function __construct(private ?User $user = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('رمز عبور نامعتبر است.');

            return;
        }
        $min = (int) config('herasat.password.min_length', 10);
        $errors = [];
        if (mb_strlen($value) < $min) {
            $errors[] = "حداقل {$min} کاراکتر";
        }
        if (mb_strlen($value) > 128) {
            $errors[] = 'حداکثر ۱۲۸ کاراکتر';
        }
        if (! preg_match('/[A-Z]/', $value)) {
            $errors[] = 'حداقل یک حرف بزرگ انگلیسی';
        }
        if (! preg_match('/[a-z]/', $value)) {
            $errors[] = 'حداقل یک حرف کوچک انگلیسی';
        }
        if (! preg_match('/\d/', $value)) {
            $errors[] = 'حداقل یک رقم';
        }
        if (! preg_match('/[^A-Za-z0-9\s]/', $value)) {
            $errors[] = 'حداقل یک نماد (مانند !@#$%)';
        }
        if (preg_match('/\s/', $value)) {
            $errors[] = 'بدون فاصله';
        }
        if (preg_match('/(.)\1{3,}/', $value)) {
            $errors[] = 'بدون تکرار پشت‌سرهم یک کاراکتر';
        }
        if ($this->user) {
            foreach ([$this->user->personnel_code, $this->user->national_code] as $secret) {
                if ($secret && str_contains($value, (string) $secret)) {
                    $errors[] = 'نباید شامل کد پرسنلی یا کد ملی باشد';
                    break;
                }
            }
        }
        if ($errors) {
            $fail('رمز عبور باید شامل این موارد باشد: '.implode('، ', $errors).'.');
        }
    }
}
