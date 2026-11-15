<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\NationalCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public static function validNationalCode(): string
    {
        do {
            $base = str_pad((string) random_int(1000000, 999999999), 9, '0', STR_PAD_LEFT);
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += ((int) $base[$i]) * (10 - $i);
            }
            $r = $sum % 11;
            $code = $base.($r < 2 ? $r : 11 - $r);
        } while (! NationalCode::isValid($code));

        return $code;
    }

    public function definition(): array
    {
        return [
            'personnel_code' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'first_name' => 'علی',
            'last_name' => 'کاربر',
            'father_name' => 'محمد',
            'employment_type' => 'رسمی',
            'department' => 'امور حراست',
            'work_location' => 'شهربابک',
            'phone' => '09131234567',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            if (! $user->national_code_hash) {
                $user->setNationalCode(self::validNationalCode());
            }
            if (! $user->password) {
                $user->password = 'Str0ng!Passw0rd';
            }
            $user->is_active ??= true;
            $user->must_change_password ??= false;
        });
    }

    public function mustChangePassword(): static
    {
        return $this->afterMaking(fn (User $u) => $u->must_change_password = true);
    }
}
