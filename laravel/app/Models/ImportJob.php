<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property-read int $id
 * @property string $filename
 * @property string|null $original_filename
 * @property string|null $stored_path
 * @property string|null $file_hash
 * @property string|null $dedupe_key
 * @property Carbon|null $processing_started_at
 * @property string $status
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $successful_rows
 * @property int $failed_rows
 * @property string|null $rejected_path
 * @property Carbon|null $rejected_expires_at
 * @property string|null $error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ImportJob extends Model
{
    protected $fillable = [
        'filename',
        'original_filename',
        'stored_path',
        'file_hash',
        'dedupe_key',
        'processing_started_at',
        'status',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'rejected_path',
        'rejected_expires_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'processing_started_at' => 'datetime',
            'rejected_expires_at' => 'datetime',
        ];
    }

    public function importErrors()
    {
        return $this->hasMany(ImportJobError::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'completed_with_errors', 'failed'], true);
    }
}
