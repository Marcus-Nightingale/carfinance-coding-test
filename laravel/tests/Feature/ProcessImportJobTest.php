<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\ImportJob;
use App\Models\ImportJobError;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessImportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_row_does_not_abort_import(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'John Doe,john@example.com,password123',
                'Broken User,not-an-email,password123',
                'Jane Doe,jane@example.com,password123',
            ])
        );

        $importJob = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($importJob))->handle();

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'not-an-email',
        ]);

        $this->assertDatabaseHas('import_jobs', [
            'id' => $importJob->id,
            'status' => 'completed_with_errors',
            'total_rows' => 3,
            'processed_rows' => 3,
            'successful_rows' => 2,
            'failed_rows' => 1,
        ]);
    }

    public function test_duplicate_email_row_does_not_roll_back_siblings(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'John Doe,john@example.com,password123',
                'John Twice,john@example.com,password123',
                'Jane Doe,jane@example.com,password123',
            ])
        );

        $importJob = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($importJob))->handle();

        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertEquals(1, User::where('email', 'john@example.com')->count());

        $this->assertDatabaseHas('import_jobs', [
            'id' => $importJob->id,
            'status' => 'completed_with_errors',
            'total_rows' => 3,
            'processed_rows' => 3,
            'successful_rows' => 2,
            'failed_rows' => 1,
        ]);
    }

    public function test_all_valid_rows_complete_cleanly(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'John Doe,john@example.com,password123',
                'Jane Doe,jane@example.com,password123',
            ])
        );

        $importJob = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($importJob))->handle();

        $this->assertDatabaseHas('import_jobs', [
            'id' => $importJob->id,
            'status' => 'completed',
            'total_rows' => 2,
            'processed_rows' => 2,
            'successful_rows' => 2,
            'failed_rows' => 0,
        ]);
        $this->assertDatabaseCount('import_job_errors', 0);
        $this->assertNull($importJob->fresh()->rejected_path);
    }

    public function test_malformed_row_preserves_safe_cells_without_password(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'John Doe,john@example.com,password123',
                'Too,Many,SecretValue,Here',
                'Jane Doe,jane@example.com,password123',
            ])
        );

        $importJob = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($importJob))->handle();

        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);

        $this->assertDatabaseHas('import_jobs', [
            'id' => $importJob->id,
            'status' => 'completed_with_errors',
            'successful_rows' => 2,
            'failed_rows' => 1,
        ]);

        $this->assertDatabaseHas('import_job_errors', [
            'import_job_id' => $importJob->id,
            'row_number' => 3,
        ]);

        $error = ImportJobError::where('import_job_id', $importJob->id)->firstOrFail();
        $this->assertSame('Too', $error->row_data['name']);
        $this->assertSame('Many', $error->row_data['email']);
        $this->assertSame(1, $error->row_data['_extra_column_count']);
        $this->assertArrayNotHasKey('password', $error->row_data);
        $this->assertStringNotContainsString('SecretValue', json_encode($error->row_data));
    }

    public function test_error_records_redact_passwords(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'Short Pass,sp@example.com,abc',
            ])
        );

        $importJob = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($importJob))->handle();

        $error = ImportJobError::where('import_job_id', $importJob->id)->firstOrFail();

        $this->assertEquals(2, $error->row_number);
        $this->assertArrayHasKey('password', $error->errors);
        $this->assertArrayNotHasKey('password', $error->row_data);
        $this->assertStringNotContainsString('abc', json_encode($error->row_data));
        $this->assertDatabaseMissing('users', ['email' => 'sp@example.com']);
    }

    public function test_blank_password_is_rejected_instead_of_using_a_shared_default(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'No Password,nopass@example.com,',
            ])
        );

        $importJob = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($importJob))->handle();

        $this->assertDatabaseMissing('users', ['email' => 'nopass@example.com']);
        $this->assertDatabaseHas('import_jobs', [
            'id' => $importJob->id,
            'status' => 'completed_with_errors',
            'failed_rows' => 1,
        ]);
    }

    public function test_bom_prefixed_header_imports_normally(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            "\xEF\xBB\xBFname,email,password\nJohn Doe,john@example.com,password123"
        );

        $importJob = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($importJob))->handle();

        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
        $this->assertDatabaseHas('import_jobs', [
            'id' => $importJob->id,
            'status' => 'completed',
            'successful_rows' => 1,
        ]);
    }

    public function test_empty_lines_are_ignored_and_not_counted_as_failed_rows(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            "name,email,password\nJohn Doe,john@example.com,password123\n\nJane Doe,jane@example.com,password123\n"
        );

        $importJob = ImportJob::create(['filename' => 'users.csv', 'status' => 'pending']);

        (new ProcessImportJob($importJob))->handle();

        $this->assertDatabaseHas('import_jobs', [
            'id' => $importJob->id,
            'status' => 'completed',
            'total_rows' => 2,
            'processed_rows' => 2,
            'successful_rows' => 2,
            'failed_rows' => 0,
        ]);
    }

    public function test_terminal_rerun_is_a_noop(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'John Doe,john@example.com,password123',
                'Broken User,not-an-email,password123',
            ])
        );

        $importJob = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'pending',
        ]);

        (new ProcessImportJob($importJob))->handle();
        $afterFirst = $importJob->fresh()->toArray();

        (new ProcessImportJob($importJob->fresh()))->handle();

        $afterSecond = $importJob->fresh()->toArray();
        $this->assertEquals($afterFirst, $afterSecond);
        $this->assertEquals(1, User::count());
        $this->assertEquals(1, ImportJobError::where('import_job_id', $importJob->id)->count());
    }

    public function test_redelivery_while_processing_does_not_duplicate_errors(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'imports/users.csv',
            implode("\n", [
                'name,email,password',
                'John Doe,john@example.com,password123',
                'Broken User,not-an-email,password123',
            ])
        );

        $importJob = ImportJob::create([
            'filename' => 'users.csv',
            'status' => 'processing',
        ]);

        ImportJobError::create([
            'import_job_id' => $importJob->id,
            'row_number' => 3,
            'row_data' => ['name' => 'Broken User', 'email' => 'not-an-email'],
            'errors' => ['email' => ['stale reason']],
        ]);

        (new ProcessImportJob($importJob))->handle();

        $this->assertEquals(
            1,
            ImportJobError::where('import_job_id', $importJob->id)->where('row_number', 3)->count()
        );
    }
}
