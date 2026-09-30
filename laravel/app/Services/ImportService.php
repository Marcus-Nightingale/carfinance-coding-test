<?php

namespace App\Services;

use App\Exceptions\ImportAlreadyActiveException;
use App\Jobs\ProcessImportJob;
use App\Models\ImportJob;
use App\Support\CsvImport;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportService
{
    public function submit(UploadedFile $file): ImportJob
    {
        $realPath = $file->getRealPath();
        $hash = $realPath === false ? false : hash_file('sha256', $realPath);

        if ($hash === false) {
            throw new RuntimeException('Unable to read the uploaded file.');
        }

        $existing = ImportJob::where('file_hash', $hash)
            ->whereIn('status', ['pending', 'processing'])
            ->orderByDesc('id')
            ->get()
            ->first(fn (ImportJob $job) => ! CsvImport::isStale($job));

        if ($existing) {
            throw new ImportAlreadyActiveException($existing->id);
        }

        $originalFilename = $file->getClientOriginalName();
        $storedPath = 'imports/'.(string) Str::uuid().'.csv';
        $stored = $file->storeAs('imports', basename($storedPath), 'local');

        if ($stored === false) {
            throw new RuntimeException('Unable to store the uploaded import file.');
        }

        try {
            try {
                $job = $this->createJob($originalFilename, $storedPath, $hash);
            } catch (QueryException $e) {
                if (! self::isDedupeKeyConflict($e)) {
                    throw $e;
                }

                $job = $this->recoverLostRace($originalFilename, $storedPath, $hash);
            }
        } catch (Throwable $e) {
            Storage::disk('local')->delete($storedPath);

            throw $e;
        }

        ProcessImportJob::dispatch($job);

        return $job;
    }

    private function recoverLostRace(string $filename, string $path, string $hash): ImportJob
    {
        $conflict = ImportJob::where('dedupe_key', $hash)->first();

        if ($conflict && CsvImport::isStale($conflict)) {
            $conflict->update([
                'status' => 'failed',
                'error_message' => 'Stale worker; lock reclaimed.',
                'dedupe_key' => null,
            ]);

            try {
                return $this->createJob($filename, $path, $hash);
            } catch (QueryException $e) {
                if (! self::isDedupeKeyConflict($e)) {
                    throw $e;
                }

                throw new ImportAlreadyActiveException(
                    ImportJob::where('dedupe_key', $hash)->value('id')
                );
            }
        }

        if ($conflict === null) {
            // A competing request may have released its key between the failed
            // insert and lookup. Retry once; the unique index remains final say.
            try {
                return $this->createJob($filename, $path, $hash);
            } catch (QueryException $e) {
                if (! self::isDedupeKeyConflict($e)) {
                    throw $e;
                }

                throw new ImportAlreadyActiveException(
                    ImportJob::where('dedupe_key', $hash)->value('id')
                );
            }
        }

        throw new ImportAlreadyActiveException($conflict->id);
    }

    private function createJob(string $filename, string $path, string $hash): ImportJob
    {
        return ImportJob::create([
            'filename' => $filename,
            'original_filename' => $filename,
            'stored_path' => $path,
            'file_hash' => $hash,
            'dedupe_key' => $hash,
            'status' => 'pending',
        ]);
    }

    private static function isDedupeKeyConflict(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $driverCode = $errorInfo[1] ?? null;
        $message = $e->getMessage();

        if ($driverCode === 1062 || str_contains($message, 'Duplicate entry')) {
            return str_contains($message, 'dedupe_key')
                || str_contains($message, 'import_jobs_dedupe_key_unique');
        }

        if ($driverCode === 19 || str_contains($message, 'UNIQUE constraint failed')) {
            return str_contains($message, 'UNIQUE constraint failed: import_jobs.dedupe_key');
        }

        return false;
    }
}
