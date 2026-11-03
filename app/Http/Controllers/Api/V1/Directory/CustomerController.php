<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Directory\CustomerRequest;
use App\Http\Resources\Directory\CustomerResource;
use App\Services\Directory\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    /** Injects the focused customer/address business service. */
    public function __construct(private readonly CustomerService $customers) {}

    /** Returns the rider's filtered resource collection with native pagination metadata. */
    public function index(CustomerRequest $request): AnonymousResourceCollection
    {
        return CustomerResource::collection($this->customers->paginate(
            $request->user(), $request->string('q')->toString(), $request->boolean('archived'), $request->integer('per_page', 25),
        ));
    }

    /** Creates an owned customer and its initial manual-delivery address. */
    public function store(CustomerRequest $request): JsonResponse
    {
        return (new CustomerResource($this->customers->save($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    /** Shows one active customer through the owner-scoped service. */
    public function show(Request $request, int $customer): CustomerResource
    {
        return new CustomerResource($this->customers->find($request->user(), $customer));
    }

    /** Edits validated identity fields without replacing address records. */
    public function update(CustomerRequest $request, int $customer): CustomerResource
    {
        return new CustomerResource($this->customers->save($request->user(), $request->validated(), $customer));
    }

    /** Archives a customer without permanently deleting its records. */
    public function destroy(Request $request, int $customer): Response
    {
        $this->customers->setArchived($request->user(), $customer, true);

        return response()->noContent();
    }

    /** Restores the customer's previous identity and address relationships. */
    public function restore(Request $request, int $customer): CustomerResource
    {
        return new CustomerResource($this->customers->setArchived($request->user(), $customer, false));
    }

    /** Adds separate address metadata and returns the updated customer. */
    public function storeAddress(CustomerRequest $request, int $customer): JsonResponse
    {
        return (new CustomerResource($this->customers->saveAddress($request->user(), $customer, $request->validated())))
            ->response()->setStatusCode(201);
    }

    /** Edits only the address that belongs to the requested owned customer. */
    public function updateAddress(CustomerRequest $request, int $customer, int $address): CustomerResource
    {
        return new CustomerResource($this->customers->saveAddress($request->user(), $customer, $request->validated(), $address));
    }

    /** Archives an address while retaining its identity and any future parcel references. */
    public function destroyAddress(Request $request, int $customer, int $address): Response
    {
        $this->customers->setAddressArchived($request->user(), $customer, $address, true);

        return response()->noContent();
    }

    /** Restores an address only through its owned, active customer. */
    public function restoreAddress(Request $request, int $customer, int $address): CustomerResource
    {
        return new CustomerResource($this->customers->setAddressArchived($request->user(), $customer, $address, false));
    }
}
