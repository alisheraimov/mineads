<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServerReviewEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
