<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    use HasFactory, SoftDeletes;

    protected $hidden = ['setup_token_hash'];

    public function latestHeartbeat(): HasOne
    {
        return $this->hasOne(ServerHeartbeat::class)->latestOfMany('received_at');
    }

    protected $guarded = [];

    protected $attributes = ['review_status' => 'awaiting_activation', 'legacy_approval' => false];

    protected function casts(): array
    {
        return [
            'setup_token_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'review_submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'legacy_approval' => 'boolean',
        ];
    }

    public function publisherAccount(): BelongsTo
    {
        return $this->belongsTo(PublisherAccount::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ServerApiToken::class);
    }
}
