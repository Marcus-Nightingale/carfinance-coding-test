<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    protected $fillable = ['name', 'key', 'enabled', 'description'];

    protected $casts = ['enabled' => 'boolean'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
