<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CollectionRequest;
use App\Http\Resources\Directory\SellerResource;
use App\Http\Resources\ParcelResource;
use App\Http\Resources\RecoveryResource;
use App\Http\Resources\RemittanceResource;
use App\Services\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CollectionController extends Controller
{
    /** Keeps bookkeeping queries and transactions out of the HTTP controller. */
    public function __construct(private readonly CollectionService $collections) {}

    /** Returns only owned confirmed receipts with separately scoped lifetime totals. */
    public function index(CollectionRequest $request): AnonymousResourceCollection
    {
        return ParcelResource::collection($this->collections->paginate($request->user(), $request->validated()))
            ->additional(['summary' => $this->collections->summary($request->user())]);
    }

    /** Returns real seller balances, including archived identities with outstanding money. */
    public function sellers(CollectionRequest $request): AnonymousResourceCollection
    {
        return SellerResource::collection($this->collections->sellers($request->user(), $request->validated()))
            ->additional(['summary' => $this->collections->summary($request->user())]);
    }

    /** Returns immutable handovers rather than external wallet transactions. */
    public function history(CollectionRequest $request): AnonymousResourceCollection
    {
        return RemittanceResource::collection($this->collections->history($request->user(), $request->validated()))
            ->additional(['summary' => $this->collections->summary($request->user())]);
    }

    /** Paginates the original parcel allocations within an owned handover detail. */
    public function show(CollectionRequest $request, int $remittance): AnonymousResourceCollection
    {
        $detail = $this->collections->show($request->user(), $remittance);

        return ParcelResource::collection($detail['allocations'])->additional(['remittance' => new RemittanceResource($detail['remittance'])]);
    }

    /** Confirms one completed handover; identical retries return its original evidence. */
    public function store(CollectionRequest $request): JsonResponse
    {
        return (new RemittanceResource($this->collections->record($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    /** Inspect a saved handover without using current balances as proof of an earlier result. */
    public function recover(CollectionRequest $request): RecoveryResource
    {
        return new RecoveryResource($this->collections->recover($request->user(), $request->validated()));
    }
}
