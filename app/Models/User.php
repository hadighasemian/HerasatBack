<?php

namespace App\Models;

use App\Support\NationalCode;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, SoftDeletes;

    protected string $guard_name = 'web';

    /**
     * Only plain profile fields are mass assignable. Security sensitive fields
     * (password, is_active, must_change_password, lockout counters, national_code_hash)
     * are always set explicitly in code.
     */
    protected $fillable = [
        'personnel_code',
        'first_name',
        'last_name',
        'father_name',
        'employment_type',
        'department',
        'work_location',
        'phone',
    ];

    protected $hidden = [
        'password',
        'national_code',
        'national_code_hash',
        'phone',
        'remember_token',
        'failed_login_attempts',
        'locked_until',
        'last_login_ip',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'national_code' => 'encrypted',
            'phone' => 'encrypted',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function setNationalCode(string $code): void
    {
        $this->national_code = $code;
        $this->national_code_hash = NationalCode::hash($code);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function maskedNationalCode(): ?string
    {
        return NationalCode::mask($this->national_code);
    }

    public function maskedPhone(): ?string
    {
        return Phone::mask($this->phone);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** The remember-me feature is intentionally disabled. */
    public function getRememberTokenName()
    {
        return '';
    }
}
