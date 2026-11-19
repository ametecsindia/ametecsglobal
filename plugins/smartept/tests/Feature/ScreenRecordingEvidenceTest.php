<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\InstallationLicense;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\ScreenRecordingEvidence;
use App\Models\User;
use App\Services\RecordingEvidence;
use App\Services\RecordingTools;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 20-Oct-2026 — Screen Recording evidence pack (fingerprints, tamper check, ZIP, chain of custody)
 * and hiding customer data (blurred copy served, original only after a logged reason).
 * The video/OCR parts run only where ffmpeg + Tesseract are installed (skipped otherwise).
 */
class ScreenRecordingEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private string $deviceToken;
    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(RecordingTools::class, 'found'))->setValue(null, []); // look for the tools afresh in each test
        $this->seed(DatabaseSeeder::class);
        Storage::fake('evidence');
        $this->companyId = (int) Company::query()->orderBy('id')->value('id');
        $userToken = $this->postJson('/api/auth/login', ['email' => 'priya.raman@ametecs.io', 'password' => 'password'])->json('token');
        $this->deviceToken = $this->withToken($userToken)->postJson('/api/agent/register-device', ['device_uuid' => 'SR-DEVICE'])->json('device_token');
        $this->withToken($this->deviceToken)->postJson('/api/agent/consent', ['device_uuid' => 'SR-DEVICE', 'acknowledged' => true])->assertCreated();
        $this->employeeId = (int) Employee::withoutGlobalScopes()->whereRelation('user', 'email', 'priya.raman@ametecs.io')->value('id');
        // Fresh test DB: roles are seeded after the permission migrations ran, so grant what a real server has.
        Role::where('slug', 'COMPANY_ADMIN')->first()->permissions()->syncWithoutDetaching(
            Permission::where('slug', 'like', 'liveview.%')->orWhere('slug', 'recording.evidence')->pluck('id'));
        InstallationLicense::current()->forceFill([
            'license_key' => 'SEPT-TEST-SREC-0000-ABCD', 'status' => 'active', 'last_checked_at' => now(),
            'bundle' => ['kind' => 'subscription', 'tier' => 'commander', 'status' => 'active',
                'features' => ['enforcement' => true, 'live_view' => true, 'recording_max_users' => 5],
                'expires_at' => now()->addYear()->toDateString(), 'grace_days' => 7, 'device_limit' => 100],
        ])->save();
        $this->admin();
        $this->postJson('/api/screen-recording/seat', ['employee_id' => $this->employeeId, 'enabled' => true])->assertOk();
    }

    private function admin(): void
    {
        $u = User::query()->whereRelation('role', 'slug', 'COMPANY_ADMIN')->firstOrFail();
        $this->withToken($this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'password'])->assertOk()->json('token'));
    }

    /** A real 20-second VP8 minute (white screen with a card number) when ffmpeg exists, else a dummy file. */
    private function video(): UploadedFile
    {
        $p = tempnam(sys_get_temp_dir(), 'sr') . '.webm';
        if (RecordingTools::ffmpeg()) {
            RecordingTools::run([RecordingTools::ffmpeg(), '-hide_banner', '-y', '-f', 'lavfi', '-i', 'color=c=white:s=1280x720:d=20:r=1',
                '-vf', "drawtext=text='Card number 4111 1111 1111 1111':fontsize=28:fontcolor=black:x=60:y=200", '-c:v', 'libvpx', '-b:v', '200k', $p], null, 60);
        }
        if (! is_file($p) || ! filesize($p)) file_put_contents($p, str_repeat('x', 4096));

        return new UploadedFile($p, 'segment.webm', 'video/webm', null, true);
    }

    private function upload(): int
    {
        $start = now()->subMinutes(5)->startOfMinute();

        return $this->withToken($this->deviceToken)->post('/api/agent/recording/upload', [
            'device_uuid' => 'SR-DEVICE', 'trigger' => 'continuous', 'started_at' => $start->toIso8601String(),
            'ended_at' => $start->copy()->addSeconds(20)->toIso8601String(), 'video' => $this->video(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
    }

    private function pack(array $over = [])
    {
        $from = now()->subMinutes(10);

        return $this->postJson('/api/screen-recording/evidence', $over + ['employee_id' => $this->employeeId, 'date' => $from->toDateString(),
            'from' => $from->format('H:i'), 'to' => now()->format('H:i'), 'monitor' => 0, 'reason' => 'Audit test case 1']);
    }

    public function test_fingerprint_evidence_pack_custody_and_tamper_check(): void
    {
        $id = $this->upload();
        $rec = ScreenRecording::withoutGlobalScopes()->find($id);
        $this->assertSame(64, strlen($rec->sha256));
        $this->assertNull($rec->verify(Storage::disk('evidence')->get($rec->storage_key)));

        $this->admin();
        $this->getJson('/api/screen-recording/recordings?employee_id=' . $this->employeeId . '&date=' . now()->toDateString())->assertOk();
        $evId = $this->pack()->assertStatus(202)->json('data.id');
        RecordingEvidence::build($evId); // what runs right after the response
        $ev = ScreenRecordingEvidence::withoutGlobalScopes()->find($evId);
        $this->assertSame('done', $ev->status, (string) $ev->error);
        $this->assertSame(1, $ev->minutes);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(storage_path('app/' . $ev->zip_path)) === true);
        foreach (['manifest.json', 'EVIDENCE.md', 'VERIFY.txt'] as $f) $this->assertNotFalse($zip->locateName($f), $f);
        $orig = null;
        for ($i = 0; $i < $zip->numFiles; $i++) if (str_starts_with($zip->getNameIndex($i), 'originals/')) $orig = $zip->getFromIndex($i);
        $this->assertSame($rec->sha256, hash('sha256', (string) $orig)); // the original in the pack matches its fingerprint
        if (RecordingTools::ffmpeg()) $this->assertNotFalse($zip->locateName($ev->code . '.mp4'));
        $zip->close();

        $show = $this->getJson('/api/screen-recording/evidence/' . $evId)->assertOk();
        $this->assertContains('Watched the recording', array_column($show->json('data.manifest.custody'), 'action'));
        $this->get('/api/screen-recording/evidence/' . $evId . '/download')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'recording.evidence_download')->exists());
        $this->postJson('/api/screen-recording/evidence/' . $evId . '/verify')->assertOk()->assertJsonPath('data.ok', true);

        // Someone edits the stored minute → verify fails, and a new pack refuses to export anything.
        Storage::disk('evidence')->put($rec->storage_key, 'tampered');
        $this->postJson('/api/screen-recording/evidence/' . $evId . '/verify')->assertOk()->assertJsonPath('data.ok', false);
        $ev2 = $this->pack()->assertStatus(202)->json('data.id');
        RecordingEvidence::build($ev2);
        $ev2 = ScreenRecordingEvidence::withoutGlobalScopes()->find($ev2);
        $this->assertSame('failed', $ev2->status);
        $this->assertStringContainsString('Fingerprint check failed', $ev2->error);
    }

    /** Force "no ffmpeg / no Tesseract on this server" — the normal case for client servers. */
    private function noServerTools(): void
    {
        $r = new \ReflectionProperty(RecordingTools::class, 'found');
        $r->setValue(null, ['ffmpeg' => null, 'tesseract' => null]);
    }

    public function test_pc_masks_and_pack_needs_no_server_tools(): void
    {
        $this->noServerTools();
        Company::whereKey($this->companyId)->update(['mask_sensitive' => true]);
        $this->withToken($this->deviceToken)->getJson('/api/agent/recording/config?device_uuid=SR-DEVICE')->assertOk()
            ->assertJsonPath('mask', \App\Services\SensitiveData::TYPES)->assertJsonStructure(['watermark' => ['company', 'employee']]);

        $start = now()->subMinutes(5)->startOfMinute();
        $up = fn ($st, $state, $masked = null) => $this->withToken($this->deviceToken)->post('/api/agent/recording/upload', array_filter([
            'device_uuid' => 'SR-DEVICE', 'trigger' => 'continuous', 'started_at' => $st->toIso8601String(), 'ended_at' => $st->copy()->addSeconds(20)->toIso8601String(),
            'video' => $this->video(), 'mask_state' => $state, 'masked_video' => $masked,
        ]), ['Accept' => 'application/json'])->assertCreated()->json('id');
        $blur = UploadedFile::fake()->createWithContent('masked.webm', 'BLURRED-COPY');
        $a = $up($start, 'masked', $blur);
        $b = $up($start->copy()->addMinute(), 'clean');
        $c = $up($start->copy()->addMinutes(2), null); // older agent: not checked

        $this->admin();
        $this->assertSame('BLURRED-COPY', $this->get('/api/screen-recording/recordings/' . $a . '/file')->assertOk()->streamedContent());
        $this->get('/api/screen-recording/recordings/' . $b . '/file')->assertOk();
        $this->get('/api/screen-recording/recordings/' . $c . '/file')->assertStatus(423);
        ScreenRecording::withoutGlobalScopes()->whereKey($c)->update(['created_at' => now()->subMinutes(10)]);
        RecordingEvidence::processMasks(5);
        $this->assertSame('failed', ScreenRecording::withoutGlobalScopes()->find($c)->mask_status); // kept hidden, no tools needed

        // Masked pack over the two checked minutes: built with no server tools; the blurred copy goes in, no originals.
        $evId = $this->postJson('/api/screen-recording/evidence', ['employee_id' => $this->employeeId, 'date' => $start->toDateString(),
            'from' => $start->format('H:i'), 'to' => $start->copy()->addMinute()->format('H:i'), 'monitor' => 0, 'reason' => 'No tools case'])->assertStatus(202)->json('data.id');
        RecordingEvidence::build($evId);
        $ev = ScreenRecordingEvidence::withoutGlobalScopes()->find($evId);
        $this->assertSame('done', $ev->status, (string) $ev->error);
        $zip = new \ZipArchive(); $zip->open(storage_path('app/' . $ev->zip_path));
        $names = []; for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
        $zip->close();
        $this->assertEmpty(array_filter($names, fn ($n) => str_starts_with($n, 'originals/')));
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_ends_with($n, '.webm')));
        $this->assertSame([true, false], array_column($ev->manifest['minutes'], 'customer_data_found'));
    }

    public function test_hidden_customer_data_is_served_blurred_and_original_needs_a_logged_reason(): void
    {
        $id = $this->upload();
        Company::whereKey($this->companyId)->update(['mask_sensitive' => true, 'mask_types' => null]);
        $this->admin();

        // Not checked yet → not playable, and not downloadable.
        $this->get('/api/screen-recording/recordings/' . $id . '/file')->assertStatus(423)->assertJsonPath('error.code', 'RECORDING_MASK_PENDING');
        $day = $this->getJson('/api/screen-recording/recordings?employee_id=' . $this->employeeId . '&date=' . now()->toDateString())->assertOk();
        $this->assertSame('pending', $day->json('data.segments.0.mask'));

        // LiveView sessions tell the agent what to blur.
        $lv = new \App\Models\LiveViewSession(['company_id' => $this->companyId, 'monitor_index' => 0, 'quality' => 'low']);
        $this->assertSame(\App\Services\SensitiveData::TYPES, (fn () => \App\Models\Company::find($this->company_id)?->maskTypes())->call($lv));

        // Unmask is its own permission, and needs a reason.
        $this->postJson('/api/screen-recording/unmask', ['employee_id' => $this->employeeId, 'date' => now()->toDateString(), 'reason' => 'dispute 42'])->assertStatus(403);
        Role::where('slug', 'COMPANY_ADMIN')->first()->permissions()->syncWithoutDetaching(Permission::where('slug', 'recording.unmask')->pluck('id'));
        $this->admin();
        $this->postJson('/api/screen-recording/unmask', ['employee_id' => $this->employeeId, 'date' => now()->toDateString(), 'reason' => 'x'])->assertStatus(422);
        $this->postJson('/api/screen-recording/unmask', ['employee_id' => $this->employeeId, 'date' => now()->toDateString(), 'reason' => 'Customer dispute 42'])->assertOk();
        $this->get('/api/screen-recording/recordings/' . $id . '/file?original=1')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'recording.unmask')->where('changes->reason', 'Customer dispute 42')->exists());

        if (! RecordingTools::ffmpeg() || ! RecordingTools::tesseract()) {
            $this->markTestSkipped('ffmpeg / Tesseract not installed — blurring not exercised');
        }
        $rec = ScreenRecording::withoutGlobalScopes()->find($id);
        $this->assertSame('masked', RecordingEvidence::maskOne($rec, \App\Services\SensitiveData::TYPES));
        $rec->refresh();
        $this->assertSame('card', $rec->mask_boxes['boxes'][0]['type']);
        $this->assertTrue(Storage::disk('evidence')->exists($rec->masked_key));
        $this->assertNull($rec->verify(Storage::disk('evidence')->get($rec->storage_key))); // original untouched
        $served = $this->get('/api/screen-recording/recordings/' . $id . '/file')->assertOk()->streamedContent();
        $this->assertSame(Storage::disk('evidence')->get($rec->masked_key), $served);  // without ?original=1 → blurred copy

        // A masked evidence pack: no originals inside, video blurred.
        $evId = $this->pack()->assertStatus(202)->json('data.id');
        RecordingEvidence::build($evId);
        $ev = ScreenRecordingEvidence::withoutGlobalScopes()->find($evId);
        $this->assertSame('done', $ev->status, (string) $ev->error);
        $this->assertTrue($ev->masked);
        $zip = new \ZipArchive(); $zip->open(storage_path('app/' . $ev->zip_path));
        for ($i = 0; $i < $zip->numFiles; $i++) $this->assertStringStartsNotWith('originals/', $zip->getNameIndex($i));
        $zip->close();
    }
}
