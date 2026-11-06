<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class NotificationController extends Controller
{
    /** Keeps creation, ownership, pagination and read-state rules in the focused service. */
    public function __construct(private readonly NotificationService $notifications) {}

    /** Returns a controlled native ledger page and its independently scoped unread count. */
    public function index(NotificationRequest $request): AnonymousResourceCollection
    {
        $result = $this->notifications->paginate($request->user(), $request->validated());

        return NotificationResource::collection($result['rows'])->additional(['unread_count' => $result['unread_count']]);
    }

    /** A small scalar response lets the workspace bell refresh without downloading the inbox. */
    public function unreadCount(NotificationRequest $request): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $this->notifications->unreadCount($request->user())]]);
    }

    /** Updates only an owned alert and exposes the same allowlisted shape as the list. */
    public function read(NotificationRequest $request, string $notification): NotificationResource
    {
        return new NotificationResource($this->notifications->markRead($request->user(), $notification));
    }

    /** The explicit bulk action affects current owned unread alerts across all pages. */
    public function readAll(NotificationRequest $request): Response
    {
        $this->notifications->markAllRead($request->user());

        return response()->noContent();
    }
}
