<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ImportJob $importJob) {}

    public function handle(): void
    {
        $this->importJob->update(['status' => 'processing']);

        $path = Storage::path('imports/' . $this->importJob->filename);

        if (! file_exists($path)) {
            $this->importJob->update([
                'status'        => 'failed',
                'error_message' => 'File not found.',
            ]);
            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, $row);
        }

        fclose($handle);

        $this->importJob->update(['total_rows' => count($rows)]);

        // TODO: Should one bad row fail the entire import?
        // Currently wraps everything in a single transaction — one invalid row rolls back all.
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                User::create([
                    'name'     => $row['name'],
                    'email'    => $row['email'],
                    'password' => bcrypt($row['password'] ?? 'password'),
                ]);
            }
        });

        $this->importJob->update([
            'status'         => 'completed',
            'processed_rows' => count($rows),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->importJob->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
