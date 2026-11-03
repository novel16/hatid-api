<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Directory\SellerRequest;
use App\Http\Resources\Directory\SellerResource;
use App\Services\Directory\SellerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SellerController extends Controller
{
    /** Injects seller preference operations without placing business logic in the controller. */
    public function __construct(private readonly SellerService $sellers) {}

    /** Returns the rider's filtered seller collection with native pagination metadata. */
    public function index(SellerRequest $request): AnonymousResourceCollection
    {
        return SellerResource::collection($this->sellers->paginate(
            $request->user(), $request->string('q')->toString(), $request->boolean('archived'), $request->integer('per_page', 25),
        ));
    }

    /** Creates an owned seller with explicit fee inclusion preferences. */
    public function store(SellerRequest $request): JsonResponse
    {
        return (new SellerResource($this->sellers->save($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    /** Shows one active seller through the owner's relation. */
    public function show(Request $request, int $seller): SellerResource
    {
        return new SellerResource($this->sellers->find($request->user(), $seller));
    }

    /** Saves validated defaults without touching existing parcel prices. */
    public function update(SellerRequest $request, int $seller): SellerResource
    {
        return new SellerResource($this->sellers->save($request->user(), $request->validated(), $seller));
    }

    /** Archives preferences without permanently deleting the seller. */
    public function destroy(Request $request, int $seller): Response
    {
        $this->sellers->setArchived($request->user(), $seller, true);

        return response()->noContent();
    }

    /** Restores the same seller identity and preferences. */
    public function restore(Request $request, int $seller): SellerResource
    {
        return new SellerResource($this->sellers->setArchived($request->user(), $seller, false));
    }
}
