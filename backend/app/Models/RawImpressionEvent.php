<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawImpressionEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'displayed_at' => 'datetime',
            'received_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function adDelivery(): BelongsTo
    {
        return $this->belongsTo(AdDelivery::class);
    }

    public function playerSession(): BelongsTo
    {
        return $this->belongsTo(PlayerSession::class);
    }
}
