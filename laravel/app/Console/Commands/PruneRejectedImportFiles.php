<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use App\Support\CsvImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneRejectedImportFiles extends Command
{
    protected $signature = 'imports:prune-rejected';

    protected $description = 'Delete expired rejected-rows CSVs and reap stale processing import jobs.';

    public function handle(): int
    {
        $disk = Storage::disk('local');

        $expired = ImportJob::whereNotNull('rejected_path')
            ->where('rejected_expires_at', '<', now())
            ->get();

        foreach ($expired as $job) {
            $disk->delete($job->rejected_path);
            $job->update(['rejected_path' => null]);
        }

        $this->reapStale();

        $this->info("Pruned {$expired->count()} rejected file(s).");

        return self::SUCCESS;
    }

    private function reapStale(): void
    {
        $threshold = now()->subMinutes(CsvImport::staleThresholdMinutes());

        ImportJob::where(function ($query) use ($threshold) {
            $query->where(function ($pending) use ($threshold) {
                $pending->where('status', 'pending')
                    ->where('updated_at', '<', $threshold);
            })->orWhere(function ($processing) use ($threshold) {
                $processing->where('status', 'processing')
                    ->where(function ($started) use ($threshold) {
                        $started->where('processing_started_at', '<', $threshold)
                            ->orWhere(function ($missingStart) use ($threshold) {
                                $missingStart->whereNull('processing_started_at')
                                    ->where('updated_at', '<', $threshold);
                            });
                    });
            });
        })
            ->chunkById(100, function ($jobs) {
                foreach ($jobs as $job) {
                    $job->update([
                        'status' => 'failed',
                        'error_message' => 'Stale worker; reaped by imports:prune-rejected.',
                        'dedupe_key' => null,
                    ]);
                }
            });
    }
}
