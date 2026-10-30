<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerReviewEvent;
use App\Models\User;

class ServerReviewService
{
    public function version(Server $server): string
    {
        return hash('sha256', json_encode([$server->only(['host', 'name', 'category', 'status', 'activated_at', 'review_status', 'review_submitted_at', 'reviewed_at', 'review_reason', 'deleted_at']), ServerReviewEvent::where('server_id', $server->id)->max('id')]));
    }

    public function record(Server $server, ?User $actor, string $action, array $extra = []): void
    {
        ServerReviewEvent::create(['server_id' => $server->id, 'actor_id' => $actor?->id, 'actor_name' => $actor?->name ?? 'System', 'action' => $action, 'details' => [...$server->only(['host', 'name', 'category', 'status', 'review_status', 'review_reason', 'suspension_reason']), ...$extra]]);
    }

    public function submit(Server $server, ?User $actor, string $action, array $extra = []): void
    {
        $server->update(['review_status' => $server->activated_at ? 'pending_review' : 'awaiting_activation', 'review_submitted_at' => $server->activated_at ? now() : null, 'reviewed_at' => null, 'review_reason' => null, 'legacy_approval' => false]);
        $this->record($server, $actor, $action, $extra);
    }
}
