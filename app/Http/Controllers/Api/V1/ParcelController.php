<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ParcelRequest;
use App\Http\Resources\ParcelResource;
use App\Services\ParcelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParcelController extends Controller
{
    /** Keeps intake/pricing/idempotency rules in one focused service. */
    public function __construct(private readonly ParcelService $parcels) {}

    /** Returns the persisted resource for both an initial save and its identical replay. */
    public function store(ParcelRequest $request): JsonResponse
    {
        return (new ParcelResource($this->parcels->create($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    /** Resolves a saved parcel through its rider without exposing a foreign ID. */
    public function show(Request $request, int $parcel): ParcelResource
    {
        return new ParcelResource($this->parcels->find($request->user(), $parcel));
    }
}
