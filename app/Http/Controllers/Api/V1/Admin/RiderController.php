<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RiderDirectoryRequest;
use App\Http\Resources\Admin\RiderResource;
use App\Services\RiderDirectoryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RiderController extends Controller
{
    /** Reuse the directory query for the administrator's paginated list. */
    public function __construct(private readonly RiderDirectoryService $riders) {}

    /** Return the approved profile fields after validating the list controls. */
    public function index(RiderDirectoryRequest $request): AnonymousResourceCollection
    {
        return RiderResource::collection($this->riders->paginate(
            $request->string('q')->toString(),
            $request->string('vehicle')->toString(),
            $request->integer('per_page', 10),
        ));
    }
}
