<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BatchRequest;
use App\Http\Resources\BatchResource;
use App\Services\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BatchController extends Controller
{
    /** Keeps intake rules in a focused service rather than the HTTP layer. */
    public function __construct(private readonly BatchService $batches) {}

    /** Returns owned server pagination and the rider's current local calendar week. */
    public function index(BatchRequest $request): AnonymousResourceCollection
    {
        return BatchResource::collection($this->batches->paginate(
            $request->user(), $request->string('q')->toString(), $request->integer('per_page', 10),
        ))->additional(['current_week' => $this->batches->currentWeek()]);
    }

    /** Creates an owned intake range and exposes only its controlled resource fields. */
    public function store(BatchRequest $request): JsonResponse
    {
        return (new BatchResource($this->batches->create($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    /** Original dates remain accessible after their week ends. */
    public function show(Request $request, int $batch): BatchResource
    {
        return new BatchResource($this->batches->find($request->user(), $batch));
    }
}
