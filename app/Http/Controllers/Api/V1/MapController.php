<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MapRequest;
use App\Http\Resources\MapResource;
use App\Services\GeoapifyService;
use App\Services\MapService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MapController extends Controller
{
    /** Route private account selection separately from fixed provider transport. */
    public function __construct(private readonly MapService $maps, private readonly GeoapifyService $provider) {}

    /** A free metadata read never starts background GPS or provider usage. */
    public function show(Request $request): MapResource
    {
        return new MapResource($this->maps->status($request->user()));
    }

    /** The service derives owned targets and the vehicle from the verified rider profile. */
    public function recommend(MapRequest $request): MapResource
    {
        return new MapResource($this->maps->recommend($request->user(), $request->validated()));
    }

    /** Keep tokens in Authorization headers and provider secrets out of browser URLs. */
    public function tile(MapRequest $request): Response
    {
        return response($this->provider->tile($request->integer('z'), $request->integer('x'), $request->integer('y')))
            ->header('Content-Type', 'image/png')->header('X-Content-Type-Options', 'nosniff');
    }
}
