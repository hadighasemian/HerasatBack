<?php

namespace App\Rules;

use App\Services\CaptchaService;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class Captcha implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = $this->data['captcha_id'] ?? null;
        if (! app(CaptchaService::class)->verify(is_string($id) ? $id : null, is_string($value) ? $value : null)) {
            $fail('کد امنیتی نادرست یا منقضی شده است.');
        }
    }
}
