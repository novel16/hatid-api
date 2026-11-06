<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Parcel;
use App\Models\Remittance;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CollectionService
{
    /** Reuse the current money transaction for one replay-safe in-app handover alert. */
    public function __construct(private readonly NotificationService $notifications) {}

    /** Only actual full successful receipts contribute to collections, fees or seller debt. */
    private function receipts(User $rider): Builder
    {
        return $rider->parcels()->getQuery()->where('status', 'delivered')->whereNotNull('received_at')
            ->whereColumn('received_amount', 'customer_total')->whereIn('receipt_method', ['cash', 'gcash']);
    }

    /** Correlates immutable allocations to one owned parcel without loading its entire history. */
    private function allocated(): QueryBuilder
    {
        return DB::table('remittance_allocations')->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('parcel_id', 'parcels.id');
    }

    /** Uses one SQL statement for lifetime totals so list filters/pages cannot distort the summary. */
    public function summary(User $rider): array
    {
        $row = $this->receipts($rider)->toBase()->selectRaw(
            "COUNT(*) AS receipts, COALESCE(SUM(CASE WHEN receipt_method = 'cash' THEN received_amount ELSE 0 END), 0) AS cash,
            COALESCE(SUM(CASE WHEN receipt_method = 'gcash' THEN received_amount ELSE 0 END), 0) AS gcash,
            COALESCE(SUM(fee), 0) AS fees, COALESCE(SUM(seller_share), 0) AS seller_share,
            (SELECT COALESCE(SUM(amount), 0) FROM remittances WHERE user_id = ?) AS remitted",
            [$rider->id],
        )->first();

        return [
            'cash' => (int) $row->cash, 'gcash' => (int) $row->gcash, 'fees' => (int) $row->fees,
            'seller_share' => (int) $row->seller_share, 'remitted' => (int) $row->remitted,
            'outstanding' => (int) $row->seller_share - (int) $row->remitted, 'receipts' => (int) $row->receipts,
        ];
    }

    /** Shares the same owned full-receipt and actual-method search scope with reports and exports. */
    public function receiptQuery(User $rider, array $filters = []): Builder
    {
        $query = $this->receipts($rider);
        if (($filters['method'] ?? 'all') !== 'all') {
            $query->where('receipt_method', $filters['method']);
        }
        $search = $filters['q'] ?? '';
        if ($search !== '') {
            /** Existing receipt identity remains searchable after a customer or seller is archived. */
            $query->where(function (Builder $matches) use ($search): void {
                $pattern = '%'.$search.'%';
                $matches->where('description', 'like', $pattern)->orWhere('receipt_reference', 'like', $pattern)
                    /** This relation includes archived customers for historical receipts. */
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $pattern))
                    /** Seller searches never bypass the enclosing private receipt scope. */
                    ->orWhereHas('seller', fn (Builder $seller) => $seller->where('name', 'like', $pattern));
                $id = ltrim(str_ireplace('HTD-', '', $search), '0');
                if (ctype_digit($id)) {
                    $matches->orWhere('parcels.id', $id);
                }
            });
        }

        return $query;
    }

    /** Keeps native collection pagination and settlement totals separate from report-period aggregates. */
    public function paginate(User $rider, array $filters): LengthAwarePaginator
    {
        return $this->receiptQuery($rider, $filters)->with(['customer', 'address', 'seller', 'batch'])
            ->select('parcels.*')->selectSub($this->allocated(), 'remitted_amount')
            ->orderByDesc('received_at')->orderByDesc('parcels.id')
            ->paginate($filters['per_page'] ?? 10)->withQueryString();
    }

    /** Includes archived sellers while deriving balances only from owned confirmed receipts/handovers. */
    public function sellers(User $rider, array $filters): LengthAwarePaginator
    {
        $receipts = $this->receipts($rider)->selectRaw('seller_id, COUNT(*) AS receipts, SUM(received_amount) AS collected, SUM(fee) AS fees, SUM(seller_share) AS accrued')->groupBy('seller_id');
        $handovers = $rider->remittances()->selectRaw('seller_id, SUM(amount) AS remitted')->groupBy('seller_id');
        $balance = 'COALESCE(receipt_totals.accrued, 0) - COALESCE(handover_totals.remitted, 0)';
        $query = $rider->sellers()->withTrashed()->leftJoinSub($receipts, 'receipt_totals', 'sellers.id', '=', 'receipt_totals.seller_id')
            ->leftJoinSub($handovers, 'handover_totals', 'sellers.id', '=', 'handover_totals.seller_id')->select('sellers.*')
            ->selectRaw('COALESCE(receipt_totals.collected, 0) AS collection_collected, COALESCE(receipt_totals.fees, 0) AS collection_fees,
                COALESCE(receipt_totals.accrued, 0) AS collection_accrued, COALESCE(receipt_totals.receipts, 0) AS collection_receipts,
                COALESCE(handover_totals.remitted, 0) AS collection_remitted, '.$balance.' AS collection_outstanding');
        if (isset($filters['seller_id'])) {
            $rider->sellers()->withTrashed()->findOrFail($filters['seller_id']);
            $query->where('sellers.id', $filters['seller_id']);
        }
        if (($filters['q'] ?? '') !== '') {
            $pattern = '%'.$filters['q'].'%';
            /** Group both seller search fields underneath rider ownership. */
            $query->where(fn (Builder $matches) => $matches->where('sellers.name', 'like', $pattern)->orWhere('sellers.contact', 'like', $pattern));
        }
        if (($filters['balance'] ?? 'all') !== 'all') {
            $query->whereRaw($balance.(($filters['balance'] === 'outstanding') ? ' > 0' : ' = 0'));
        }

        return $query->orderBy('sellers.name')->orderBy('sellers.id')->paginate($filters['per_page'] ?? 10)->withQueryString();
    }

    /** Paginates handover evidence independently from the original customer receipt method. */
    public function history(User $rider, array $filters): LengthAwarePaginator
    {
        $query = $rider->remittances()->with('seller')->withCount('parcels');
        if (($filters['method'] ?? 'all') !== 'all') {
            $query->where('method', $filters['method']);
        }
        if (($filters['q'] ?? '') !== '') {
            $search = $filters['q'];
            /** Optional reference, seller name and internal code remain within this rider's ledger. */
            $query->where(function (Builder $matches) use ($search): void {
                $pattern = '%'.$search.'%';
                $matches->where('reference', 'like', $pattern)
                    /** Archived seller names remain visible on saved evidence. */
                    ->orWhereHas('seller', fn (Builder $seller) => $seller->where('name', 'like', $pattern));
                $id = ltrim(str_ireplace('RMT-', '', $search), '0');
                if (ctype_digit($id)) {
                    $matches->orWhere('remittances.id', $id);
                }
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 10)->withQueryString();
    }

    /** @return array{remittance: Remittance, allocations: LengthAwarePaginator} */
    public function show(User $rider, int $id): array
    {
        $record = $rider->remittances()->with('seller')->withCount('parcels')->findOrFail($id);
        $allocations = $record->parcels()->where('parcels.user_id', $rider->id)->with(['customer', 'address', 'seller', 'batch'])
            ->select('parcels.*')->selectSub($this->allocated(), 'remitted_amount')
            ->orderBy('received_at')->orderBy('parcels.id')->paginate(10)->withQueryString();

        return ['remittance' => $record, 'allocations' => $allocations];
    }

    /** Records the actual handover exactly once, allocating oldest outstanding seller shares first. */
    public function record(User $rider, array $values): Remittance
    {
        $payload = [
            'seller_id' => (int) $values['seller_id'], 'amount' => $values['amount'], 'method' => $values['method'],
            'reference' => ($values['reference'] ?? '') !== '' ? $values['reference'] : null,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        /** Match Deliveries' rider-first lock so simultaneous receipts/handovers share one money boundary. */
        return DB::transaction(function () use ($rider, $values, $payload, $hash): Remittance {
            $account = User::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $previous = $rider->remittances()->where('operation_id', $values['operation_id'])->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $hash), 409, 'This operation already recorded a different handover. Review its saved history.');

                return $previous->load('seller')->loadCount('parcels');
            }
            $rider->sellers()->withTrashed()->lockForUpdate()->findOrFail($payload['seller_id']);
            $owed = $this->receipts($rider)->where('seller_id', $payload['seller_id'])
                ->where('seller_share', '>', 0)->select(['parcels.id', 'seller_share'])->selectSub($this->allocated(), 'remitted_amount')
                ->orderBy('received_at')->orderBy('parcels.id')->get();
            /** Previously remitted shares are unavailable even when another device just submitted them. */
            $available = $owed->sum(fn (Parcel $parcel): int => max(0, $parcel->seller_share - (int) $parcel->remitted_amount));
            if ($payload['amount'] > $available) {
                throw ValidationException::withMessages(['amount' => 'The amount exceeds this seller’s current outstanding share. Refresh the balance and review the handover.']);
            }
            $record = $rider->remittances()->create([...$payload, 'operation_id' => $values['operation_id'], 'request_hash' => $hash]);
            $remaining = $payload['amount'];
            $allocations = [];
            foreach ($owed as $parcel) {
                if ($remaining === 0) {
                    break;
                }
                $amount = min($remaining, max(0, $parcel->seller_share - (int) $parcel->remitted_amount));
                if ($amount > 0) {
                    $allocations[$parcel->id] = ['amount' => $amount];
                    $remaining -= $amount;
                }
            }
            if ($remaining !== 0) {
                throw ValidationException::withMessages(['amount' => 'The seller share changed. Review the balance before recording again.']);
            }
            foreach (array_chunk($allocations, 100, true) as $chunk) {
                $record->parcels()->attach($chunk);
            }
            $this->notifications->remittance($account, $record);

            return $record->load('seller')->loadCount('parcels');
        }, 3);
    }
}
