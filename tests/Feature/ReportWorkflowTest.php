<?php

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->fakeCaptcha();
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'title' => 'مشاهده رفتار مشکوک',
            'description' => 'در تاریخ گذشته یک فرد ناشناس در محوطه انبار مشاهده شد که اقدام به عکاسی می‌کرد.',
            'category' => 'physical',
            'contact' => '09130000000',
        ], $this->captchaFields(), $extra);
    }

    private function submit(array $extra = [])
    {
        return $this->postJson('/api/public/reports', $this->payload($extra));
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        $img = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_public_submission_returns_tracking_code_and_stores_only_its_hash(): void
    {
        $res = $this->submit()->assertCreated();
        $code = $res->json('tracking_code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}(-[A-Z0-9]{4}){3}$/', $code);
        $report = Report::first();
        $this->assertSame(Report::STATUS_SUBMITTED, $report->status);
        $this->assertSame(hash('sha256', str_replace('-', '', $code)), $report->tracking_hash);
        $this->assertDatabaseMissing('reports', ['tracking_hash' => $code]);
        $this->assertNotSame('09130000000', $report->getRawOriginal('contact')); // encrypted at rest
        $this->assertSame(1, $report->histories()->count());
        // reporter anonymity: no IP recorded
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.submitted', 'ip' => null]);
    }

    public function test_tracking_lookup(): void
    {
        $code = $this->submit()->json('tracking_code');
        $this->postJson('/api/public/reports/track', ['tracking_code' => strtolower($code)] + $this->captchaFields())
            ->assertOk()->assertJsonPath('status', 'submitted')->assertJsonMissingPath('title')->assertJsonMissingPath('description');
        $this->postJson('/api/public/reports/track', ['tracking_code' => 'AAAA-BBBB-CCCC-DDDD'] + $this->captchaFields())->assertStatus(404);
        $this->postJson('/api/public/reports/track', ['tracking_code' => $code])->assertStatus(422);
    }

    public function test_validation(): void
    {
        $this->submit(['title' => 'ab'])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->travel(61)->seconds();
        $this->submit(['description' => 'short'])->assertStatus(422)->assertJsonValidationErrors('description');
        $this->travel(61)->seconds();
        $this->submit(['category' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_workflow_visibility_and_review(): void
    {
        $this->submit()->assertCreated();
        $this->travel(61)->seconds();
        $this->submit(['title' => 'گزارش دوم برای رد'])->assertCreated();
        [$r1, $r2] = Report::orderBy('id')->get()->all();

        $head = $this->makeUser(['user', 'head_personnel_security']);
        $supervisor = $this->makeUser(['user', 'personnel_security_supervisor']);
        $manager = $this->makeUser(['user', 'security_affairs_manager']);
        $other = $this->makeUser(['user', 'head_it_support']);

        // before review: supervisor sees nothing, head sees both
        $this->actingAs($head)->getJson('/api/reports')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('can_review', true);
        $this->actingAs($supervisor)->getJson('/api/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($supervisor)->getJson("/api/reports/{$r1->id}")->assertStatus(403);
        $this->actingAs($other)->getJson('/api/reports')->assertStatus(403);
        $this->actingAs($other)->getJson("/api/reports/{$r1->id}")->assertStatus(403);

        // only reviewers can review
        $this->actingAs($supervisor)->postJson("/api/reports/{$r1->id}/review", ['decision' => 'approve'])->assertStatus(403);
        $this->actingAs($manager)->postJson("/api/reports/{$r1->id}/review", ['decision' => 'approve'])->assertStatus(403);

        // reject needs a note
        $this->actingAs($head)->postJson("/api/reports/{$r2->id}/review", ['decision' => 'reject'])->assertStatus(422);
        $this->actingAs($head)->postJson("/api/reports/{$r2->id}/review", ['decision' => 'reject', 'note' => 'تکراری'])->assertOk()->assertJsonPath('report.status', 'rejected');
        $this->actingAs($head)->postJson("/api/reports/{$r1->id}/review", ['decision' => 'approve', 'note' => 'ارجاع شد'])->assertOk()->assertJsonPath('report.status', 'approved');
        // cannot be reviewed twice
        $this->actingAs($head)->postJson("/api/reports/{$r1->id}/review", ['decision' => 'reject', 'note' => 'x'])->assertStatus(422);

        // after review: supervisor sees only the approved one
        $this->actingAs($supervisor)->getJson('/api/reports')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $r1->id);
        $this->actingAs($supervisor)->getJson("/api/reports/{$r1->id}")->assertOk()->assertJsonPath('report.contact', '09130000000')
            ->assertJsonCount(2, 'report.history');
        $this->actingAs($supervisor)->getJson("/api/reports/{$r2->id}")->assertStatus(403);
        // the manager (view_all) sees both read-only
        $this->actingAs($manager)->getJson('/api/reports')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('can_review', false);

        // audit trail
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.approved', 'user_id' => $head->id, 'subject_id' => $r1->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.rejected', 'user_id' => $head->id, 'subject_id' => $r2->id]);
        $this->assertDatabaseHas('report_status_histories', ['report_id' => $r1->id, 'from_status' => 'submitted', 'to_status' => 'approved', 'user_id' => $head->id]);

        // public tracking reflects the decision
        $this->assertSame('approved', $r1->fresh()->status);
    }

    public function test_valid_attachment_is_stored_privately_and_downloadable_only_when_authorized(): void
    {
        $this->submit(['attachments' => [$this->png()]])->assertCreated();
        $report = Report::with('attachments')->first();
        $att = $report->attachments->first();
        $this->assertSame('image/png', $att->mime);
        $this->assertStringStartsWith('reports/', $att->stored_path);
        $this->assertStringNotContainsString('photo', $att->stored_path); // random stored name
        Storage::disk('local')->assertExists($att->stored_path);
        $this->assertFalse(str_contains($att->stored_path, 'public'));

        $head = $this->makeUser(['user', 'head_personnel_security']);
        $supervisor = $this->makeUser(['user', 'personnel_security_supervisor']);
        $this->actingAs($supervisor)->get("/api/reports/{$report->id}/attachments/{$att->id}")->assertStatus(403);
        $res = $this->actingAs($head)->get("/api/reports/{$report->id}/attachments/{$att->id}")->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
    }

    public function test_dangerous_or_invalid_uploads_are_rejected(): void
    {
        $cases = [
            UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]); ?>'),
            UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>'),       // fake image
            UploadedFile::fake()->createWithContent('doc.pdf', "MZ\x90\x00 not a pdf"),                 // exe disguised as pdf
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
            UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),                           // too big
        ];
        foreach ($cases as $i => $file) {
            $this->travel(61)->seconds();
            $this->submit(['attachments' => [$file]])->assertStatus(422);
        }
        $this->travel(61)->seconds();
        $this->submit(['attachments' => [$this->png('a.png'), $this->png('b.png'), $this->png('c.png'), $this->png('d.png')]])
            ->assertStatus(422)->assertJsonValidationErrors('attachments');
        $this->assertSame(0, Report::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_png_renamed_to_pdf_is_rejected(): void
    {
        $png = $this->png();
        $file = UploadedFile::fake()->createWithContent('fake.pdf', file_get_contents($png->getRealPath()));
        $this->submit(['attachments' => [$file]])->assertStatus(422);
    }

    public function test_submission_is_rate_limited(): void
    {
        $this->submit()->assertCreated();
        $this->submit()->assertCreated();
        $this->submit()->assertCreated();
        $this->submit()->assertStatus(429);
    }
}
