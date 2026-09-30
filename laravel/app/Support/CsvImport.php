<?php

namespace App\Support;

use App\Models\ImportJob;

class CsvImport
{
    /**
     * Normalise a parsed CSV header row: strip a leading UTF-8 BOM from the
     * first cell (Excel/Sheets exports), trim whitespace/CR per cell.
     *
     * @param  array<int, mixed>  $header
     * @return array<int, string>
     */
    public static function normaliseHeader(array $header): array
    {
        $cells = array_map(fn ($c) => trim((string) $c), $header);

        if (isset($cells[0])) {
            $cells[0] = ltrim($cells[0], "\xEF\xBB\xBF");
        }

        return array_values($cells);
    }

    /**
     * @return array<int, string>
     */
    public static function expectedHeaders(): array
    {
        return config('imports.expected_headers', ['name', 'email', 'password']);
    }

    public static function headerValid(array $header): bool
    {
        return self::normaliseHeader($header) === self::expectedHeaders();
    }

    public static function staleThresholdMinutes(): int
    {
        return (int) config('imports.stale_after_minutes', 30);
    }

    public static function isStale(ImportJob $job): bool
    {
        if (! in_array($job->status, ['pending', 'processing'], true)) {
            return false;
        }

        $lastActivity = $job->status === 'processing'
            ? ($job->processing_started_at ?? $job->updated_at)
            : ($job->updated_at ?? $job->created_at);

        return $lastActivity !== null
            && $lastActivity->lt(now()->subMinutes(self::staleThresholdMinutes()));
    }
}
