<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'setup_token_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
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
