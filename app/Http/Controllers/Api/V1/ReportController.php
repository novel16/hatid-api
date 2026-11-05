<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Http\Resources\ParcelResource;
use App\Http\Resources\ReportResource;
use App\Services\ReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** Keeps all date/receipt/aggregate/export rules inside the focused report service. */
    public function __construct(private readonly ReportService $reports) {}

    /** Returns one controlled ledger page with complete matching period totals and trends. */
    public function index(ReportRequest $request): AnonymousResourceCollection
    {
        $result = $this->reports->earnings($request->user(), $request->validated());

        return ParcelResource::collection($result['rows'])->additional(['report' => new ReportResource($result['report'])]);
    }

    /** Streams a fully prepared private CSV and always releases its temporary file. */
    public function export(ReportRequest $request): StreamedResponse
    {
        $export = $this->reports->export($request->user(), $request->validated());

        return response()->streamDownload(function () use ($export): void {
            try {
                fpassthru($export['stream']);
            } finally {
                fclose($export['stream']);
            }
        }, $export['name'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Length' => (string) $export['size']]);
    }

    /** Today's confirmed earnings and lifetime debt have explicit independent scopes. */
    public function dashboard(ReportRequest $request): ReportResource
    {
        return new ReportResource($this->reports->dashboard($request->user()));
    }
}
