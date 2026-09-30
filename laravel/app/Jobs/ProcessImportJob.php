<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\ImportJobError;
use App\Models\User;
use App\Support\CsvImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public ImportJob $importJob) {}

    public function handle(): void
    {
        $this->importJob->refresh();

        // No supported same-job rerun: re-dispatch after a terminal state is a no-op.
        if ($this->importJob->isTerminal()) {
            return;
        }

        $storedPath = $this->importJob->stored_path
            ?? ('imports/'.$this->importJob->filename);
        $disk = Storage::disk('local');

        if (! $disk->exists($storedPath)) {
            $this->markFailed('File not found.');

            return;
        }

        $this->importJob->update([
            'status' => 'processing',
            'processing_started_at' => now(),
        ]);

        $fullPath = $disk->path($storedPath);
        $handle = fopen($fullPath, 'r');

        if ($handle === false) {
            $this->markFailed('Unable to open import file.');

            return;
        }

        $header = fgetcsv($handle);

        if ($header === false || ! CsvImport::headerValid($header)) {
            fclose($handle);
            $this->markFailed('Invalid CSV header. Expected: name,email,password.');

            return;
        }

        // Use the normalised header (BOM stripped, trimmed) as the row keys so
        // a BOM-prefixed file maps to name/email/password correctly.
        $header = CsvImport::normaliseHeader($header);

        $flushEvery = max(1, (int) config('imports.chunk_flush', 100));
        $total = 0;
        $processed = 0;
        $success = 0;
        $failed = 0;
        $rowNumber = 1; // header is row 1

        try {
            while (($fields = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Ignore wholly-empty lines: they are not data rows.
                if ($fields === [null] || (count($fields) === 1 && trim((string) $fields[0]) === '')) {
                    continue;
                }

                $total++;

                if (count($fields) !== count($header)) {
                    $this->recordError(
                        $rowNumber,
                        $this->malformedRowData($header, $fields),
                        ['row' => ['Column count does not match header.']]
                    );
                    $failed++;
                    $processed++;
                } else {
                    $combined = array_combine($header, $fields);
                    assert($combined !== false);

                    $name = trim((string) ($combined['name'] ?? ''));
                    $email = trim((string) ($combined['email'] ?? ''));
                    $password = $combined['password'] ?? null;
                    $password = is_string($password) ? trim($password) : $password;

                    $row = ['name' => $name, 'email' => $email, 'password' => $password];

                    $validator = Validator::make($row, [
                        'name' => 'required|string|max:255',
                        'email' => 'required|email|unique:users,email',
                        'password' => 'required|string|min:8',
                    ]);

                    if ($validator->fails()) {
                        $this->recordError($rowNumber, $row, $validator->errors()->toArray());
                        $failed++;
                    } else {
                        try {
                            User::create([
                                'name' => $row['name'],
                                'email' => $row['email'],
                                'password' => Hash::make($row['password']),
                            ]);
                            $success++;
                        } catch (QueryException $e) {
                            if (! self::isEmailDuplicate($e)) {
                                throw $e;
                            }
                            $this->recordError($rowNumber, $row, [
                                'email' => ['This email is already taken.'],
                            ]);
                            $failed++;
                        }
                    }
                    $processed++;
                }

                if ($processed % $flushEvery === 0) {
                    $this->flushProgress($total, $processed, $success, $failed);
                }
            }
        } catch (\Throwable $e) {
            fclose($handle);
            $this->flushProgress($total, $processed, $success, $failed);
            $this->markFailed($e->getMessage(), $failed > 0);

            throw $e;
        }

        fclose($handle);

        $status = $failed === 0 ? 'completed' : 'completed_with_errors';

        $rejectedPath = null;
        $rejectedExpiresAt = null;

        if ($failed > 0) {
            $rejectedPath = 'imports/rejected/'.$this->importJob->id.'.csv';
            if (! $this->writeRejectedCsv($rejectedPath)) {
                $this->markFailed('Unable to write rejected-rows CSV.', true);

                throw new \RuntimeException('Unable to write rejected-rows CSV.');
            }
            $rejectedExpiresAt = now()->addDays((int) config('imports.rejected_ttl_days', 7));
        }

        $this->importJob->update([
            'status' => $status,
            'total_rows' => $total,
            'processed_rows' => $processed,
            'successful_rows' => $success,
            'failed_rows' => $failed,
            'rejected_path' => $rejectedPath,
            'rejected_expires_at' => $rejectedExpiresAt,
            'dedupe_key' => null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        // Safety net only (fatal/timeout where handle()'s catch never ran).
        // Never invents counts: preserves whatever was last flushed.
        $this->importJob->refresh();

        if ($this->importJob->isTerminal()) {
            return;
        }

        $rejectedPath = null;
        $rejectedExpiresAt = null;

        if ($this->importJob->failed_rows > 0) {
            $rejectedPath = 'imports/rejected/'.$this->importJob->id.'.csv';
            if (! $this->writeRejectedCsv($rejectedPath)) {
                $rejectedPath = null;
            } else {
                $rejectedExpiresAt = now()->addDays((int) config('imports.rejected_ttl_days', 7));
            }
        }

        $this->importJob->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'rejected_path' => $rejectedPath,
            'rejected_expires_at' => $rejectedExpiresAt,
            'dedupe_key' => null,
        ]);
    }

    private function flushProgress(int $total, int $processed, int $success, int $failed): void
    {
        $this->importJob->update([
            'total_rows' => $total,
            'processed_rows' => $processed,
            'successful_rows' => $success,
            'failed_rows' => $failed,
            'processing_started_at' => now(),
        ]);
    }

    private function markFailed(string $message, bool $hasRowErrors = false): void
    {
        $rejectedPath = null;
        $rejectedExpiresAt = null;

        if ($hasRowErrors) {
            $rejectedPath = 'imports/rejected/'.$this->importJob->id.'.csv';
            if (! $this->writeRejectedCsv($rejectedPath)) {
                $rejectedPath = null;
            } else {
                $rejectedExpiresAt = now()->addDays((int) config('imports.rejected_ttl_days', 7));
            }
        }

        $this->importJob->update([
            'status' => 'failed',
            'error_message' => $message,
            'rejected_path' => $rejectedPath,
            'rejected_expires_at' => $rejectedExpiresAt,
            'dedupe_key' => null,
        ]);
    }

    /**
     * Store a redacted error row: never persists the password value.
     */
    private function recordError(int $rowNumber, array $row, array $errors): void
    {
        $safeKeys = ['name', 'email', '_extra_column_count', '_missing_columns'];

        ImportJobError::updateOrCreate(
            ['import_job_id' => $this->importJob->id, 'row_number' => $rowNumber],
            [
                'row_data' => [
                    ...array_intersect_key($row, array_flip($safeKeys)),
                ],
                'errors' => $errors,
            ]
        );
    }

    private function malformedRowData(array $header, array $fields): array
    {
        $row = [];

        foreach ($header as $index => $column) {
            if ($column === 'password') {
                continue;
            }

            $row[$column] = $fields[$index] ?? null;
        }

        if (count($fields) > count($header)) {
            $row['_extra_column_count'] = count($fields) - count($header);
        } elseif (count($fields) < count($header)) {
            $row['_missing_columns'] = array_values(array_filter(
                array_slice($header, count($fields)),
                fn (string $column) => $column !== 'password'
            ));
        }

        return $row;
    }

    private function writeRejectedCsv(string $rejectedPath): bool
    {
        $errors = ImportJobError::where('import_job_id', $this->importJob->id)
            ->orderBy('row_number')
            ->get();

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['row_number', 'name', 'email', 'errors']);

        foreach ($errors as $error) {
            $rowData = $error->row_data ?? [];
            $flat = collect($error->errors ?? [])
                ->map(fn ($messages, $field) => $field.': '.implode(', ', (array) $messages))
                ->values()
                ->implode('; ');
            fputcsv($stream, [
                self::safeCsvCell($error->row_number),
                self::safeCsvCell($rowData['name'] ?? ''),
                self::safeCsvCell($rowData['email'] ?? ''),
                self::safeCsvCell($flat),
            ]);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return Storage::disk('local')->put($rejectedPath, $contents);
    }

    private static function safeCsvCell(mixed $value): string
    {
        $value = (string) $value;
        $formulaCandidate = ltrim($value, " \t\r\n\x00\x0B\xEF\xBB\xBF");

        if ($formulaCandidate !== '' && str_contains('=+-@', $formulaCandidate[0])) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * True only for a proven UNIQUE violation on users.email — never for other
     * 23000-class errors (NOT NULL, CHECK, other indexes) or outages.
     */
    public static function isEmailDuplicate(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;

        if ($sqlState !== '23000' && $e->getCode() !== '23000' && (int) $e->getCode() !== 23000) {
            // Fall back to message inspection when errorInfo is unavailable.
            if (! str_contains($e->getMessage(), '23000')) {
                return false;
            }
        }

        $driverCode = $errorInfo[1] ?? null;
        $message = $e->getMessage();

        // MySQL: error 1062 naming the email unique index.
        if ($driverCode === 1062 || str_contains($message, 'Duplicate entry')) {
            return str_contains($message, 'users_email_unique')
                || str_contains($message, 'users.email')
                || str_contains($message, "'email'");
        }

        // SQLite: error 19 with the exact table.column in the message.
        if ($driverCode === 19 || str_contains($message, 'UNIQUE constraint failed')) {
            return str_contains($message, 'UNIQUE constraint failed: users.email');
        }

        return false;
    }
}
