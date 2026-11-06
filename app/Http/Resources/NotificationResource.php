<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification */
class NotificationResource extends JsonResource
{
    /** Expose display text and a fixed record target without raw data, owner IDs or arbitrary URLs. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'category' => $this->type,
            'title' => $this->data['title'], 'message' => $this->data['message'],
            'target' => ['kind' => $this->data['target']['kind'], 'id' => (int) $this->data['target']['id']],
            'read_at' => $this->read_at?->toISOString(), 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
