<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdCreative extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected $attributes = ['is_paused' => false];

    protected function casts(): array
    {
        return ['is_paused' => 'boolean'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
