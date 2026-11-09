<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RiderDirectoryRequest;
use App\Http\Resources\Admin\RiderResource;
use App\Http\Resources\Admin\RiderStatusActivityResource;
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
            $request->string('status')->toString(),
            $request->integer('per_page', 10),
        ));
    }

    /** Return the current profile revision alongside its paginated activity. */
    public function activity(RiderDirectoryRequest $request, int $rider): AnonymousResourceCollection
    {
        $activity = $this->riders->activity($rider);

        return RiderStatusActivityResource::collection($activity['history'])
            ->additional(['rider' => new RiderResource($activity['rider'])]);
    }

    /** Apply only the administrator's validated status transition. */
    public function updateStatus(RiderDirectoryRequest $request, int $rider): RiderResource
    {
        return new RiderResource($this->riders->changeStatus(
            $request->user(),
            $rider,
            $request->validated('status'),
            $request->validated('reason'),
            $request->integer('expected_revision'),
        ));
    }
}
