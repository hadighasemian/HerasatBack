<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the initial administrator with a random strong password. The credentials are written to a
 * file (ADMIN_CREDENTIALS_PATH, default storage/app/private/ADMIN_CREDENTIALS.txt), never printed.
 * The admin must change the password at first login.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = (string) env('ADMIN_USERNAME', 'admin');
        if (User::withTrashed()->where('personnel_code', $username)->exists()) {
            $this->command?->info('Admin user already exists, skipped.');

            return;
        }

        $password = $this->randomPassword();
        $user = new User([
            'personnel_code' => $username,
            'first_name' => 'مدیر',
            'last_name' => 'سیستم',
        ]);
        // Placeholder national code (checksum valid, not a real person). Admin should set a real one later.
        $nc = (string) env('ADMIN_NATIONAL_CODE', '');
        $user->setNationalCode(\App\Support\NationalCode::isValid($nc) ? $nc : $this->placeholderNationalCode());
        $user->password = $password;
        $user->must_change_password = true;
        $user->is_active = true;
        $user->save();
        $user->assignRole(['admin', 'user']);

        $path = (string) env('ADMIN_CREDENTIALS_PATH', storage_path('app/private/ADMIN_CREDENTIALS.txt'));
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, "Herasat initial administrator\nusername: {$username}\npassword: {$password}\n\nChange this password at first login and then delete this file.\n");
        @chmod($path, 0600);
        $this->command?->warn("Admin user created. Credentials written to: {$path}");
    }

    private function randomPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*-_=+?'];
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        while (count($chars) < 20) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** A checksum-valid code starting with 000 that will not collide with imported real codes in practice. */
    private function placeholderNationalCode(): string
    {
        do {
            $base = '000'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += ((int) $base[$i]) * (10 - $i);
            }
            $r = $sum % 11;
            $code = $base.($r < 2 ? $r : 11 - $r);
        } while (! \App\Support\NationalCode::isValid($code) || User::withTrashed()->where('national_code_hash', \App\Support\NationalCode::hash($code))->exists());

        return $code;
    }
}
