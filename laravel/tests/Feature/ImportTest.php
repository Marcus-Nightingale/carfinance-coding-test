<?php

namespace Tests\Feature;

use App\Models\ImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_upload_is_accepted(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\nJohn Doe,john@example.com,secret"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['id', 'status']);
        $this->assertEquals('pending', $response->json('status'));
        $this->assertDatabaseHas('import_jobs', ['filename' => 'users.csv']);
    }

    public function test_upload_requires_a_file(): void
    {
        $response = $this->postJson('/api/imports', []);

        $response->assertStatus(422);
    }

    public function test_import_status_can_be_retrieved(): void
    {
        $job = ImportJob::create([
            'filename'       => 'users.csv',
            'status'         => 'completed',
            'total_rows'     => 10,
            'processed_rows' => 10,
        ]);

        $response = $this->getJson("/api/imports/{$job->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'filename'       => 'users.csv',
            'status'         => 'completed',
            'total_rows'     => 10,
            'processed_rows' => 10,
        ]);
    }

    public function test_import_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/imports/99999');

        $response->assertStatus(404);
    }
}
