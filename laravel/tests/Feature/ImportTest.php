<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\ImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_upload_is_accepted(): void
    {
        Bus::fake();
        Queue::fake();
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\nJohn Doe,john@example.com,password123"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['id', 'status']);
        $this->assertEquals('pending', $response->json('status'));

        $this->assertDatabaseHas('import_jobs', ['filename' => 'users.csv']);
        Bus::assertDispatched(ProcessImportJob::class);
    }

    public function test_upload_requires_a_file(): void
    {
        $response = $this->postJson('/api/imports', []);

        $response->assertStatus(422);
        $this->assertDatabaseCount('import_jobs', 0);
    }

    public function test_import_status_can_be_retrieved(): void
    {
        $id = DB::table('import_jobs')->insertGetId([
            'filename' => 'users.csv',
            'status' => 'completed',
            'total_rows' => 10,
            'processed_rows' => 10,
        ]);

        $response = $this->getJson("/api/imports/{$id}");

        $response->assertStatus(200);
        $response->assertJson([
            'filename' => 'users.csv',
            'status' => 'completed',
            'total_rows' => 10,
            'processed_rows' => 10,
        ]);
    }

    public function test_import_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/imports/99999');

        $response->assertStatus(404);
    }

    public function test_upload_rejects_bad_header_without_storing(): void
    {
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "fullname,emailaddress\nJohn Doe,john@example.com"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('header');
        $this->assertDatabaseCount('import_jobs', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
    }

    public function test_upload_accepts_bom_prefixed_header(): void
    {
        Bus::fake();
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "\xEF\xBB\xBFname,email,password\nJohn Doe,john@example.com,password123"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $response->assertStatus(202);
        $this->assertDatabaseCount('import_jobs', 1);
    }

    public function test_upload_uses_unique_internal_filename(): void
    {
        Bus::fake();
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\nJohn Doe,john@example.com,password123"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);
        $response->assertStatus(202);

        $job = ImportJob::findOrFail($response->json('id'));

        $this->assertEquals('users.csv', $job->filename);
        $this->assertNotEquals('imports/users.csv', $job->stored_path);
        $this->assertTrue(Storage::disk('local')->exists($job->stored_path));
        $this->assertNotNull($job->file_hash);
        $this->assertEquals($job->file_hash, $job->dedupe_key);
    }

    public function test_duplicate_upload_returns_409_without_orphan_file(): void
    {
        Bus::fake();
        Storage::fake('local');

        $content = "name,email,password\nJohn Doe,john@example.com,password123";

        $first = $this->postJson('/api/imports', [
            'file' => UploadedFile::fake()->createWithContent('users.csv', $content),
        ]);
        $first->assertStatus(202);

        $second = $this->postJson('/api/imports', [
            'file' => UploadedFile::fake()->createWithContent('users.csv', $content),
        ]);

        $second->assertStatus(409);
        $second->assertJson(['existing_job_id' => $first->json('id')]);
        $this->assertDatabaseCount('import_jobs', 1);
        // No unreferenced copy left behind by the rejected upload.
        $this->assertCount(1, Storage::disk('local')->allFiles('imports'));
    }

    public function test_reupload_after_completion_is_allowed(): void
    {
        Bus::fake();
        Storage::fake('local');

        $content = "name,email,password\nJohn Doe,john@example.com,password123";

        ImportJob::create([
            'filename' => 'users.csv',
            'original_filename' => 'users.csv',
            'stored_path' => 'imports/old.csv',
            'file_hash' => hash('sha256', $content),
            'dedupe_key' => null,
            'status' => 'completed',
        ]);

        $response = $this->postJson('/api/imports', [
            'file' => UploadedFile::fake()->createWithContent('users.csv', $content),
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseCount('import_jobs', 2);
    }

    public function test_upload_reaps_stale_processing_job(): void
    {
        Bus::fake();
        Storage::fake('local');

        $content = "name,email,password\nJohn Doe,john@example.com,password123";

        $stale = ImportJob::create([
            'filename' => 'users.csv',
            'original_filename' => 'users.csv',
            'stored_path' => 'imports/stale.csv',
            'file_hash' => hash('sha256', $content),
            'dedupe_key' => hash('sha256', $content),
            'status' => 'processing',
            'processing_started_at' => now()->subMinutes(31),
        ]);

        $response = $this->postJson('/api/imports', [
            'file' => UploadedFile::fake()->createWithContent('users.csv', $content),
        ]);

        $response->assertStatus(202);
        $this->assertEquals('failed', $stale->fresh()->status);
        $this->assertNull($stale->fresh()->dedupe_key);
    }

    public function test_upload_reaps_stale_pending_job(): void
    {
        Bus::fake();
        Storage::fake('local');

        $content = "name,email,password\nJohn Doe,john@example.com,password123";
        $hash = hash('sha256', $content);

        $stale = ImportJob::create([
            'filename' => 'users.csv',
            'original_filename' => 'users.csv',
            'stored_path' => 'imports/pending.csv',
            'file_hash' => $hash,
            'dedupe_key' => $hash,
            'status' => 'pending',
        ]);
        DB::table('import_jobs')->where('id', $stale->id)->update([
            'created_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(31),
        ]);

        $response = $this->postJson('/api/imports', [
            'file' => UploadedFile::fake()->createWithContent('users.csv', $content),
        ]);

        $response->assertStatus(202);
        $this->assertEquals('failed', $stale->fresh()->status);
        $this->assertNull($stale->fresh()->dedupe_key);
        $this->assertDatabaseCount('import_jobs', 2);
    }

    public function test_status_response_exposes_counters(): void
    {
        $job = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'completed_with_errors',
            'total_rows' => 3,
            'processed_rows' => 3,
            'successful_rows' => 2,
            'failed_rows' => 1,
        ]);

        $response = $this->getJson("/api/imports/{$job->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'successful_rows' => 2,
            'failed_rows' => 1,
            'errors_count' => 0,
        ]);
    }

    public function test_rejected_csv_download_contains_reasons_not_passwords(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'John Doe,john@example.com,password123',
                '=1+1,bad@example.com,short',
            ])
        );

        $job = ImportJob::create([
            'filename' => 'users.csv',
            'stored_path' => 'imports/users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($job))->handle();

        $response = $this->get('/api/imports/'.$job->id.'/errors');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $body = $response->streamedContent() ?? $response->getContent();
        $this->assertStringContainsString('row_number,name,email,errors', (string) $body);
        $this->assertStringContainsString('bad@example.com', (string) $body);
        $this->assertStringContainsString("'=1+1", (string) $body);
        $this->assertStringNotContainsString('short', (string) $body);
    }

    public function test_errors_download_404_when_no_failures(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'John Doe,john@example.com,password123',
            ])
        );

        $job = ImportJob::create([
            'filename' => 'users.csv',
            'stored_path' => 'imports/users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($job))->handle();

        $this->get('/api/imports/'.$job->id.'/errors')->assertStatus(404);
    }

    public function test_prune_command_deletes_expired_rejected_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/rejected/1.csv', 'row_number,name,email,errors');

        $job = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'completed_with_errors',
            'rejected_path' => 'imports/rejected/1.csv',
            'rejected_expires_at' => now()->subDay(),
        ]);

        $this->artisan('imports:prune-rejected')->assertSuccessful();

        Storage::disk('local')->assertMissing('imports/rejected/1.csv');
        $this->assertNull($job->fresh()->rejected_path);
    }
}
