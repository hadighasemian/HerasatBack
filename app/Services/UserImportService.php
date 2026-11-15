<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\NationalCode;
use App\Support\Persian;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use RuntimeException;

/**
 * Imports users from CSV/XLSX. Header names are matched after normalization (trimmed, Persian
 * letters normalized, spaces/ZWNJ removed), so "کدملی", "کد ملی " and "national_code" all match.
 * Default password = national code (hashed); every imported user must change it on first login.
 */
class UserImportService
{
    /** field => accepted header aliases */
    public const HEADER_ALIASES = [
        'personnel_code' => ['شماره پرسنلی 6 رقمی', 'شماره پرسنلی', 'کد پرسنلی', 'شماره پرسنلی ۶ رقمی', 'personnel_code', 'personnel code', 'username'],
        'national_code' => ['کدملی', 'کد ملی', 'national_code', 'national code'],
        'first_name' => ['نام', 'first_name', 'first name'],
        'last_name' => ['نام خانوادگی', 'نام‌خانوادگی', 'last_name', 'last name'],
        'father_name' => ['نام پدر', 'father_name', 'father name'],
        'employment_type' => ['نوع استخدام', 'employment_type', 'employment type'],
        'department' => ['امور سازمانی', 'امور', 'واحد', 'department'],
        'work_location' => ['محل خدمت', 'work_location', 'work location'],
        'phone' => ['تلفن', 'تلفن همراه', 'موبایل', 'phone', 'mobile'],
        'role' => ['نقش', 'role'],
    ];

    public const REQUIRED = ['personnel_code', 'national_code', 'first_name', 'last_name'];

    private ?int $bcryptRounds = null;

    public function import(string $path, array $options = []): array
    {
        $updateExisting = (bool) ($options['update'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $sheetName = $options['sheet'] ?? null;
        $progress = $options['progress'] ?? null;
        $this->bcryptRounds = min((int) config('herasat.auth.import_bcrypt_rounds', 10), (int) config('hashing.bcrypt.rounds', 12));

        $rows = $this->readRows($path, $sheetName);
        if (count($rows) < 1) {
            throw new RuntimeException('فایل خالی است.');
        }
        $map = $this->mapHeaders(array_shift($rows));

        $result = [
            'total_rows' => 0, 'created' => 0, 'updated' => 0,
            'skipped_existing' => 0, 'skipped_empty' => 0, 'duplicates_in_file' => 0,
            'invalid_national_code' => 0, 'invalid_personnel_code' => 0, 'missing_required' => 0,
            'national_code_conflict' => 0, 'unknown_role' => 0,
            'errors' => [], // row number + reason only (no personal data)
        ];

        $defaultRole = Role::where('name', 'user')->where('guard_name', 'web')->first();
        $rolesByName = Role::all()->keyBy(fn ($r) => mb_strtolower($r->name));
        $rolesByDisplay = Role::all()->keyBy(fn ($r) => Persian::normalizeText((string) $r->display_name));
        $seenPersonnel = [];
        $seenNational = [];
        $padTo = (int) config('herasat.import.personnel_code_pad', 6);

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // 1-based + header
            $get = fn (string $field) => isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;

            if ($this->rowIsEmpty($row)) {
                $result['skipped_empty']++;
                continue;
            }
            $result['total_rows']++;
            if ($progress) {
                $progress();
            }

            $personnel = $this->normalizeCode($get('personnel_code'));
            if ($personnel !== null && strlen($personnel) < $padTo) {
                $personnel = str_pad($personnel, $padTo, '0', STR_PAD_LEFT);
            }
            $national = NationalCode::normalize($get('national_code'));
            $first = Persian::normalizeText((string) $get('first_name'));
            $last = Persian::normalizeText((string) $get('last_name'));

            if ($personnel === null && $national === null && $first === '' && $last === '') {
                $result['skipped_empty']++;
                $result['total_rows']--;
                continue;
            }
            if ($personnel === null || $first === '' || $last === '' || $get('national_code') === null || $get('national_code') === '') {
                $result['missing_required']++;
                $this->addError($result, $rowNumber, 'missing_required');
                continue;
            }
            if (strlen($personnel) > 20) {
                $result['invalid_personnel_code']++;
                $this->addError($result, $rowNumber, 'invalid_personnel_code');
                continue;
            }
            if (! NationalCode::isValid($national)) {
                $result['invalid_national_code']++;
                $this->addError($result, $rowNumber, 'invalid_national_code');
                continue;
            }
            if (isset($seenPersonnel[$personnel]) || isset($seenNational[$national])) {
                $result['duplicates_in_file']++;
                $this->addError($result, $rowNumber, 'duplicate_in_file');
                continue;
            }
            $seenPersonnel[$personnel] = true;
            $seenNational[$national] = true;

            $profile = [
                'first_name' => mb_substr($first, 0, 100),
                'last_name' => mb_substr($last, 0, 100),
                'father_name' => $this->nullableText($get('father_name'), 100),
                'employment_type' => $this->nullableText($get('employment_type'), 100),
                'department' => $this->nullableText($get('department'), 191),
                'work_location' => $this->nullableText($get('work_location'), 191),
                'phone' => Phone::normalize($get('phone')),
            ];

            $extraRole = null;
            $roleValue = Persian::normalizeText((string) $get('role'));
            if ($roleValue !== '') {
                $extraRole = $rolesByName[mb_strtolower($roleValue)] ?? $rolesByDisplay[$roleValue] ?? null;
                if (! $extraRole) {
                    $result['unknown_role']++;
                    $this->addError($result, $rowNumber, 'unknown_role');
                }
            }

            $existing = User::withTrashed()->where('personnel_code', $personnel)->first();
            $nationalHash = NationalCode::hash($national);
            $conflict = User::withTrashed()->where('national_code_hash', $nationalHash)
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists();
            if ($conflict) {
                $result['national_code_conflict']++;
                $this->addError($result, $rowNumber, 'national_code_belongs_to_another_user');
                continue;
            }

            if ($existing && ! $updateExisting) {
                $result['skipped_existing']++;
                continue;
            }
            if ($dryRun) {
                $existing ? $result['updated']++ : $result['created']++;
                continue;
            }

            DB::transaction(function () use ($existing, $personnel, $national, $profile, $defaultRole, $extraRole, &$result) {
                if ($existing) {
                    $existing->fill($profile);
                    $existing->setNationalCode($national);
                    $existing->save();
                    $user = $existing;
                    $result['updated']++;
                } else {
                    $user = new User(['personnel_code' => $personnel] + $profile);
                    $user->setNationalCode($national);
                    // Pre-hash with the (lower) import cost; the 'hashed' cast keeps an existing hash as is.
                    $user->password = Hash::make($national, ['rounds' => $this->bcryptRounds]);
                    $user->must_change_password = true;
                    $user->is_active = true;
                    $user->save();
                    $result['created']++;
                }
                if ($defaultRole && ! $user->hasRole($defaultRole)) {
                    $user->assignRole($defaultRole);
                }
                if ($extraRole && ! $user->hasRole($extraRole)) {
                    $user->assignRole($extraRole);
                }
            });
        }

        $result['error_count'] = count($result['errors']);
        $result['errors'] = array_slice($result['errors'], 0, 200);

        return $result;
    }

    /** @return array<int, array<int, mixed>> */
    private function readRows(string $path, ?string $sheetName): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('فایل یافت نشد.');
        }
        $reader = IOFactory::createReaderForFile($path);
        if ($reader instanceof Csv) {
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
        }
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $sheet = null;
        if ($sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if (! $sheet) {
                throw new RuntimeException('برگه موردنظر در فایل یافت نشد.');
            }
        } else {
            // the sheet whose header row contains the required columns, otherwise the first sheet
            foreach ($spreadsheet->getAllSheets() as $candidate) {
                $header = $candidate->rangeToArray('A1:'.$candidate->getHighestColumn().'1', null, false, false, false)[0] ?? [];
                try {
                    $this->mapHeaders($header);
                    $sheet = $candidate;
                    break;
                } catch (RuntimeException) {
                    // try next sheet
                }
            }
            $sheet ??= $spreadsheet->getSheet(0);
        }

        // raw values (no formatting) so numbers keep all digits
        return $sheet->toArray(null, false, false, false);
    }

    /** @return array<string,int> field => column index */
    public function mapHeaders(array $header): array
    {
        $normalizedAliases = [];
        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $normalizedAliases[Persian::normalizeHeader($alias)] = $field;
            }
        }
        $map = [];
        foreach ($header as $i => $title) {
            $key = Persian::normalizeHeader(is_scalar($title) ? (string) $title : '');
            if ($key !== '' && isset($normalizedAliases[$key]) && ! isset($map[$normalizedAliases[$key]])) {
                $map[$normalizedAliases[$key]] = $i;
            }
        }
        $missing = array_diff(self::REQUIRED, array_keys($map));
        if ($missing) {
            throw new RuntimeException('ستون‌های الزامی در فایل یافت نشد: '.implode(', ', $missing));
        }

        return $map;
    }

    private function normalizeCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            $value = number_format($value, 0, '', '');
        }
        $v = preg_replace('/\s+/', '', Persian::toEnglishDigits((string) $value)) ?? '';

        return ($v !== '' && ctype_digit($v)) ? $v : null;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = Persian::normalizeText(Persian::toEnglishDigits(is_float($value) ? number_format($value, 0, '', '') : (string) $value));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function addError(array &$result, int $row, string $reason): void
    {
        $result['errors'][] = ['row' => $row, 'reason' => $reason];
    }
}
