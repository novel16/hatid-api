<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ReportService
{
    private const TIMEZONE = 'Asia/Manila';

    /** Reuses Collections' receipt eligibility/search and lifetime seller balance rules. */
    public function __construct(private readonly CollectionService $collections) {}

    /** Resolves local calendar days once; queries use the following midnight as an exclusive end. */
    private function period(array $filters): array
    {
        $kind = $filters['period'] ?? 'week';
        $anchor = isset($filters['date'])
            ? CarbonImmutable::parse($filters['date'], self::TIMEZONE)->startOfDay()
            : CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        if ($kind === 'custom') {
            $start = CarbonImmutable::parse($filters['start'], self::TIMEZONE)->startOfDay();
            $end = CarbonImmutable::parse($filters['end'], self::TIMEZONE)->startOfDay();
        } elseif ($kind === 'month') {
            $start = $anchor->startOfMonth();
            $end = $anchor->endOfMonth()->startOfDay();
        } elseif ($kind === 'day') {
            $start = $end = $anchor;
        } else {
            $start = $anchor->startOfWeek(CarbonImmutable::MONDAY);
            $end = $start->addDays(6);
        }

        return ['kind' => $kind, 'anchor' => $kind === 'custom' ? null : $anchor->toDateString(), 'start' => $start, 'end' => $end];
    }

    /** Receipt dates, not intake batch dates or remittance timestamps, define earnings recognition. */
    private function query(User $rider, array $filters, array $period): Builder
    {
        return $this->collections->receiptQuery($rider, $filters)
            ->where('parcels.received_at', '>=', $period['start']->utc()->toDateTimeString())
            ->where('parcels.received_at', '<', $period['end']->addDay()->utc()->toDateTimeString());
    }

    /** Aggregates stored centavos in bounded chunks; at most60 true-value chart buckets are returned. */
    private function aggregate(Builder $query, array $period): array
    {
        $days = (int) $period['start']->diffInDays($period['end']) + 1;
        $stride = max(1, (int) ceil($days / 60));
        $trend = [];
        for ($offset = 0; $offset < $days; $offset += $stride) {
            $trend[] = [
                'start' => $period['start']->addDays($offset)->toDateString(),
                'end' => $period['start']->addDays(min($offset + $stride - 1, $days - 1))->toDateString(),
                'receipts' => 0, 'fees' => 0,
            ];
        }
        $summary = ['receipts' => 0, 'cash' => 0, 'gcash' => 0, 'fees' => 0, 'seller_share' => 0, 'total' => 0];
        foreach ((clone $query)->select(['parcels.id', 'received_at', 'received_amount', 'receipt_method', 'fee', 'seller_share'])->lazyById(200, 'parcels.id', 'id') as $parcel) {
            $localDay = $parcel->received_at->setTimezone(self::TIMEZONE)->startOfDay();
            $index = intdiv((int) $period['start']->diffInDays($localDay), $stride);
            $summary['receipts']++;
            $summary[$parcel->receipt_method] += $parcel->received_amount;
            $summary['total'] += $parcel->received_amount;
            $summary['fees'] += $parcel->fee;
            $summary['seller_share'] += $parcel->seller_share;
            $trend[$index]['receipts']++;
            $trend[$index]['fees'] += $parcel->fee;
        }

        return [
            'period' => ['kind' => $period['kind'], 'anchor' => $period['anchor'], 'start' => $period['start']->toDateString(), 'end' => $period['end']->toDateString(), 'timezone' => self::TIMEZONE],
            'bucket_days' => $stride, 'summary' => $summary, 'trend' => $trend,
            'generated_at' => CarbonImmutable::now('UTC')->toISOString(),
        ];
    }

    /** Returns one ledger page while summaries and trends cover every matching confirmed receipt. */
    public function earnings(User $rider, array $filters): array
    {
        $period = $this->period($filters);

        /** A short read transaction keeps totals, chart and current-page rows on one database snapshot. */
        return DB::transaction(function () use ($rider, $filters, $period): array {
            $query = $this->query($rider, $filters, $period);

            return [
                'report' => $this->aggregate($query, $period),
                'rows' => (clone $query)->with(['customer', 'address', 'seller', 'batch'])
                    ->orderByDesc('received_at')->orderByDesc('parcels.id')->paginate($filters['per_page'] ?? 10)->withQueryString(),
            ];
        });
    }

    /** Distinguishes current ready work, today's actual receipts/fees and lifetime seller debt. */
    public function dashboard(User $rider): array
    {
        $period = $this->period(['period' => 'day']);

        /** Read-only snapshots do not acquire the rider money-mutation lock. */
        return DB::transaction(function () use ($rider, $period): array {
            $report = $this->aggregate($this->query($rider, [], $period), $period);
            $statuses = $rider->parcels()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
            $held = (int) ($statuses['held'] ?? 0);
            $failed = (int) ($statuses['failed'] ?? 0);
            $report['work'] = [
                'ready' => $rider->parcels()->ready()->count(),
                'open' => (int) ($statuses['pending'] ?? 0) + $held + $failed,
                'held' => $held, 'failed' => $failed,
            ];
            $report['seller_outstanding'] = $this->collections->summary($rider)['outstanding'];

            return $report;
        });
    }

    /** Exact centavo formatting avoids floating point rounding in an exported financial record. */
    private function decimal(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Treat spreadsheet formulas in human-owned names as literal text, preserving their original value. */
    private function csvText(string $value): string
    {
        return preg_match('/^\s*[=+@-]/u', $value) === 1 || preg_match('/^[\t\r\n]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Fail before download headers if a CSV row cannot be fully written.
     *
     * @param  resource  $stream
     */
    private function writeRow(mixed $stream, array $values): void
    {
        if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to finish the report export. Please try again.');
        }
    }

    /**
     * Prepare the complete private CSV on disk, with bounded row batches and no pagination limit.
     *
     * @return array{name: string, stream: resource, size: int}
     */
    public function export(User $rider, array $filters): array
    {
        $period = $this->period($filters);
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare the report export. Please try again.');
        }
        try {
            /** Finish a chunked, consistent export on disk before sending successful download headers. */
            DB::transaction(function () use ($rider, $filters, $period, $stream): void {
                if (fwrite($stream, "\xEF\xBB\xBF") !== 3) {
                    throw new RuntimeException('Unable to prepare the report export.');
                }
                $this->writeRow($stream, ['Parcel', 'Customer', 'Seller', 'Intake batch', 'Confirmed at (Asia/Manila)', 'Actual payment method', 'Received PHP', 'Rider fee PHP', 'Seller share PHP']);
                $rows = $this->query($rider, $filters, $period)->with(['customer', 'seller', 'batch'])->orderByDesc('received_at')->orderByDesc('parcels.id');
                foreach ($rows->lazy(200) as $parcel) {
                    $this->writeRow($stream, [
                        'HTD-'.str_pad((string) $parcel->id, 6, '0', STR_PAD_LEFT),
                        $this->csvText($parcel->customer->name), $this->csvText($parcel->seller->name), $this->csvText($parcel->batch->name),
                        $parcel->received_at->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:sP'), $parcel->receipt_method,
                        $this->decimal($parcel->received_amount), $this->decimal($parcel->fee), $this->decimal($parcel->seller_share),
                    ]);
                }
            });
            if (! rewind($stream)) {
                throw new RuntimeException('Unable to read the finished report export.');
            }

            return ['name' => 'hatid-earnings-'.$period['start']->toDateString().'-to-'.$period['end']->toDateString().'.csv', 'stream' => $stream, 'size' => (int) fstat($stream)['size']];
        } catch (Throwable $cause) {
            fclose($stream);
            throw $cause;
        }
    }
}
