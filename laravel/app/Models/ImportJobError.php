<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJobError extends Model
{
    protected $fillable = [
        'import_job_id',
        'row_number',
        'row_data',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'row_data' => 'array',
            'errors' => 'array',
        ];
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}
