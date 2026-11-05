<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ParcelRequest;
use App\Http\Resources\DeliveryResource;
use App\Http\Resources\ParcelResource;
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeliveryController extends Controller
{
    /** Delegates stop grouping and manual outcome rules to the focused delivery service. */
    public function __construct(private readonly DeliveryService $deliveries) {}

    /** Uses Laravel pagination over matching private customer/address stops. */
    public function index(ParcelRequest $request): AnonymousResourceCollection
    {
        return DeliveryResource::collection($this->deliveries->paginate($request->user(), $request->validated()));
    }

    /** An owned parcel is an anchor for all work at its customer/address, across weeks. */
    public function show(Request $request, int $parcel): DeliveryResource
    {
        return new DeliveryResource($this->deliveries->find($request->user(), $parcel));
    }

    /** Returns updated selected parcels only after the whole manual handover is saved. */
    public function outcome(ParcelRequest $request, int $parcel): AnonymousResourceCollection
    {
        return ParcelResource::collection($this->deliveries->outcome($request->user(), $parcel, $request->validated()));
    }

    /** Saves an optional pin independently of the already confirmed delivery and receipt. */
    public function location(ParcelRequest $request, int $parcel): DeliveryResource
    {
        $this->deliveries->saveLocation($request->user(), $parcel, $request->validated());

        return new DeliveryResource($this->deliveries->find($request->user(), $parcel));
    }
}
