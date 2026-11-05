<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Batch */
class BatchResource extends JsonResource
{
    /**
     * Formats original dates and a calendar badge, without inventing delivery progress or fees.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $start = $this->start_date->toDateString();
        $end = $this->end_date->toDateString();
        $today = now('Asia/Manila')->toDateString();
        $period = 'current';
        if ($end < $today) {
            $period = 'previous';
        } elseif ($start > $today) {
            $period = 'upcoming';
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'start' => $start,
            'end' => $end,
            'period' => $period,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
