<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Http\Resources\Auth\UserResource;
use App\Services\SettingsService;

class SettingsController extends Controller
{
    /** Saves validated account settings and returns the shared controlled account shape. */
    public function update(SettingsRequest $request, SettingsService $settings): UserResource
    {
        return new UserResource($settings->update($request->user(), $request->validated()));
    }
}
