<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserImportService;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class UserImportTest extends TestCase
{
    /** Same layout as the real HR export: sheet "شاغلین", Persian headers with trailing spaces, numeric cells. */
    private function makeXlsx(array $rows): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('شاغلین');
        $sheet->fromArray(['ردیف ', 'شماره پرسنلی 6 رقمی ', 'نام ', 'نام خانوادگی ', 'نام پدر ', 'نوع استخدام ', 'کدملی ', 'امور سازمانی ', 'محل خدمت ', 'تلفن '], null, 'A1');
        $sheet->fromArray($rows, null, 'A2', true);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    private function codeWithLeadingZero(): string
    {
        do {
            $c = UserFactory::validNationalCode();
        } while ($c[0] !== '0');

        return $c;
    }

    public function test_imports_real_layout_restores_leading_zeros_and_counts_problems(): void
    {
        $nc1 = $this->codeWithLeadingZero();
        $nc2 = UserFactory::validNationalCode();
        $path = $this->makeXlsx([
            [1, 100001, 'علي', 'رضايي', 'حسن', 'رسمی', (int) $nc1, 'امور حراست', 'شهربابک', 9131234567],
            [2, 100002, 'مریم', 'کریمی', 'احمد', 'قراردادی', (int) $nc2, 'امور مالی', 'میدوک', null],
            [3, 100003, 'نامعتبر', 'کد', 'x', 'y', 1234567890, 'z', 'w', null],   // bad checksum
            [4, 100001, 'تکراری', 'پرسنلی', 'x', 'y', (int) UserFactory::validNationalCode(), 'z', 'w', null], // duplicate personnel code
            [5, null, null, null, null, null, null, null, null, null],           // empty row
            [6, 100006, '', 'بی‌نام', 'x', 'y', (int) UserFactory::validNationalCode(), 'z', 'w', null], // missing first name
        ]);

        $r = app(UserImportService::class)->import($path);
        $this->assertSame(2, $r['created']);
        $this->assertSame(1, $r['invalid_national_code']);
        $this->assertSame(1, $r['duplicates_in_file']);
        $this->assertSame(1, $r['missing_required']);

        $u = User::where('personnel_code', '100001')->first();
        $this->assertSame($nc1, $u->national_code);       // leading zero restored
        $this->assertSame('علی', $u->first_name);          // Arabic ي normalized
        $this->assertSame('حسن', $u->father_name);
        $this->assertSame('رسمی', $u->employment_type);
        $this->assertSame('امور حراست', $u->department);
        $this->assertSame('شهربابک', $u->work_location);
        $this->assertSame('09131234567', $u->phone);       // leading 0 restored
        $this->assertTrue($u->must_change_password);
        $this->assertTrue($u->hasRole('user'));
        $this->assertTrue(Hash::check($nc1, $u->password));

        // errors contain only row numbers and reasons, never personal data
        $json = json_encode($r['errors']);
        $this->assertStringNotContainsString('1234567890', $json);
        $this->assertStringNotContainsString('تکراری', $json);

        // re-import: existing users are skipped, passwords untouched
        $hash = $u->password;
        $r2 = app(UserImportService::class)->import($path);
        $this->assertSame(0, $r2['created']);
        $this->assertSame(2, $r2['skipped_existing']);
        $this->assertSame($hash, $u->fresh()->password);
        @unlink($path);
    }

    public function test_imported_user_can_login_with_national_code_and_must_change_password(): void
    {
        $nc = $this->codeWithLeadingZero();
        $path = $this->makeXlsx([[1, 4321, 'تست', 'کاربر', 'x', 'رسمی', (int) $nc, 'a', 'b', null]]);
        app(UserImportService::class)->import($path);
        // 4-digit personnel code is padded to 6 digits
        $this->withHeaders($this->spa)->postJson('/api/auth/login', ['username' => '004321', 'password' => $nc])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
        @unlink($path);
    }

    public function test_csv_with_english_headers_and_role_column(): void
    {
        $nc = UserFactory::validNationalCode();
        $csv = "personnel_code,national_code,first_name,last_name,department,role\n200001,{$nc},Ali,Test,IT,head_it_support\n";
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $csv);
        $r = app(UserImportService::class)->import($path);
        $this->assertSame(1, $r['created']);
        $this->assertEqualsCanonicalizing(['user', 'head_it_support'], User::where('personnel_code', '200001')->first()->roles->pluck('name')->all());
        @unlink($path);
    }

    public function test_missing_required_columns_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "foo,bar\n1,2\n");
        $this->expectException(\RuntimeException::class);
        app(UserImportService::class)->import($path);
    }

    public function test_artisan_command(): void
    {
        $nc = UserFactory::validNationalCode();
        $path = $this->makeXlsx([[1, 300001, 'الف', 'ب', 'پ', 'رسمی', (int) $nc, 'a', 'b', 9130000000]]);
        $this->artisan('herasat:import-users', ['file' => $path, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseMissing('users', ['personnel_code' => '300001']);
        $this->artisan('herasat:import-users', ['file' => $path])->assertSuccessful();
        $this->assertDatabaseHas('users', ['personnel_code' => '300001']);
        @unlink($path);
    }

    public function test_admin_upload_endpoint_and_permission(): void
    {
        $nc = UserFactory::validNationalCode();
        $path = $this->makeXlsx([[1, 400001, 'الف', 'ب', 'پ', 'رسمی', (int) $nc, 'a', 'b', null]]);
        $file = new UploadedFile($path, 'users.xlsx', null, null, true);

        $this->actingAs($this->makeUser(['user']))->postJson('/api/admin/users/import', ['file' => $file])->assertStatus(403);

        $admin = $this->makeUser(['admin']);
        $this->actingAs($admin)->postJson('/api/admin/users/import', ['file' => $file])
            ->assertOk()->assertJsonPath('result.created', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.import', 'user_id' => $admin->id]);

        // a non-spreadsheet file is rejected
        $bad = UploadedFile::fake()->createWithContent('users.xlsx', '<?php echo 1;');
        $this->actingAs($admin)->postJson('/api/admin/users/import', ['file' => $bad])->assertStatus(422);
    }
}
