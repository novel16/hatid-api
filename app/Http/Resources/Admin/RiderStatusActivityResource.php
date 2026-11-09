<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiderStatusActivityResource extends JsonResource
{
    /** Expose the original actor, decision, reason and time for the profile history. */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            'admin_id' => (int) $this->admin_id,
            'admin_name' => $this->admin_name,
            'created_at' => CarbonImmutable::parse($this->created_at)->toISOString(),
        ];
    }
}
