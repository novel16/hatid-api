<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    /** Allowlists calendar context, integer aggregates and optional dashboard-only scope labels. */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'period' => [
                'kind' => $data['period']['kind'], 'anchor' => $data['period']['anchor'],
                'start' => $data['period']['start'], 'end' => $data['period']['end'], 'timezone' => 'Asia/Manila',
            ],
            'summary' => [
                'receipts' => (int) $data['summary']['receipts'], 'cash' => (int) $data['summary']['cash'],
                'gcash' => (int) $data['summary']['gcash'], 'fees' => (int) $data['summary']['fees'],
                'seller_share' => (int) $data['summary']['seller_share'], 'total' => (int) $data['summary']['total'],
            ],
            'bucket_days' => (int) $data['bucket_days'],
            /** Zero buckets stay zero; no fixture chart or fabricated minimum earnings. */
            'trend' => array_map(fn (array $bucket): array => [
                'start' => $bucket['start'], 'end' => $bucket['end'],
                'receipts' => (int) $bucket['receipts'], 'fees' => (int) $bucket['fees'],
            ], $data['trend']),
            'generated_at' => $data['generated_at'],
            /** Dashboard work is across all intake weeks, rather than the report's payment date range. */
            'work' => $this->when(isset($data['work']), fn (): array => [
                'ready' => (int) $data['work']['ready'], 'open' => (int) $data['work']['open'],
                'held' => (int) $data['work']['held'], 'failed' => (int) $data['work']['failed'],
            ]),
            'seller_outstanding' => $this->when(isset($data['seller_outstanding']), fn (): int => (int) $data['seller_outstanding']),
        ];
    }
}
