<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'avatar_url' => $this->avatar_url,
            'email_verified_at' => $this->email_verified_at,
            'google_connected' => $this->google_id !== null,
            'permissions' => $this->role === 'admin'
                ? $this->getAllPermissions()->pluck('name')->values()
                : [],
            'advertiser_account' => $this->whenLoaded('advertiserAccount', fn () => $this->advertiserAccount ? [
                'id' => $this->advertiserAccount->id,
                'name' => $this->advertiserAccount->name,
                'status' => $this->advertiserAccount->status,
                'currency' => $this->advertiserAccount->currency,
            ] : null),
            'publisher_account' => $this->whenLoaded('publisherAccount', fn () => $this->publisherAccount ? [
                'id' => $this->publisherAccount->id,
                'name' => $this->publisherAccount->name,
                'status' => $this->publisherAccount->status,
                'currency' => $this->publisherAccount->currency,
            ] : null),
        ];
    }
}
