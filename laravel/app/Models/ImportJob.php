<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $fillable = [
        'filename',
        'status',
        'total_rows',
        'processed_rows',
        'error_message',
    ];
}
