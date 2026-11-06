<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Remittance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationService
{
    /** Keeps this inbox separate from foreign owners and future framework notification types. */
    private function query(User $rider): Builder
    {
        return $rider->notifications()->getQuery()->reorder()->whereIn('type', ['delivery', 'collection']);
    }

    /** Counts every owned unread alert, independently of the displayed page or read filter. */
    public function unreadCount(User $rider): int
    {
        return $this->query($rider)->whereNull('read_at')->count();
    }

    /** Reads native pagination and its full unread count from one short database snapshot. */
    public function paginate(User $rider, array $filters): array
    {
        /** Notification reads acquire no money locks and never change saved delivery evidence. */
        return DB::transaction(function () use ($rider, $filters): array {
            $query = $this->query($rider);
            if (($filters['state'] ?? 'all') === 'unread') {
                $query->whereNull('read_at');
            }

            return [
                'rows' => $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 10)->withQueryString(),
                'unread_count' => $this->unreadCount($rider),
            ];
        });
    }

    /** Preserve the first read time, including concurrent or retried read confirmations. */
    public function markRead(User $rider, string $id): DatabaseNotification
    {
        $record = $this->query($rider)->findOrFail($id);
        $this->query($rider)->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return $record->refresh();
    }

    /** Marks all current owned unread alerts across pages without deleting or rewriting past activity. */
    public function markAllRead(User $rider): void
    {
        $this->query($rider)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Called once after a new outcome, inside the existing replay-safe owner transaction. */
    public function delivery(User $lockedRider, int $parcelId, string $outcome, int $count): void
    {
        if ($outcome === 'delivered' && $lockedRider->settingPreferences()['collectionAlerts']) {
            $this->create($lockedRider, 'collection', 'Delivery & payment confirmed', $count.' '.Str::plural('parcel', $count).'. Full customer receipts were manually confirmed. Open the stop for cash/GCash records.', 'delivery', $parcelId);

            return;
        }
        $title = match ($outcome) {
            'delivered' => 'Delivery confirmed', 'held' => 'Delivery on hold',
            'failed' => 'Delivery attempt failed', 'pending' => 'Delivery resumed',
            'returned' => 'Return to seller recorded',
        };
        $this->create($lockedRider, 'delivery', $title, $count.' '.Str::plural('parcel', $count).'. Open the stop to review the saved outcome and history.', 'delivery', $parcelId);
    }

    /** Creates in-app activity for a completed external handover, never a payment transfer. */
    public function remittance(User $lockedRider, Remittance $record): void
    {
        $this->create($lockedRider, 'collection', 'Seller remittance recorded', 'Seller share handover confirmed. View the saved amount and parcel allocations.', 'remittance', $record->id);
    }

    /** Preferences suppress only new alerts; all existing alerts and business history are preserved. */
    private function create(User $lockedRider, string $category, string $title, string $message, string $kind, int $id): void
    {
        $preference = $category === 'delivery' ? 'deliveryAlerts' : 'collectionAlerts';
        if (! $lockedRider->settingPreferences()[$preference]) {
            return;
        }
        $lockedRider->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => $category,
            'data' => ['title' => $title, 'message' => $message, 'target' => ['kind' => $kind, 'id' => $id]],
            'read_at' => null,
        ]);
    }
}
