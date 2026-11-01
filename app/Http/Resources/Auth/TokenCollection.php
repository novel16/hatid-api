<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TokenCollection extends ResourceCollection
{
    public $collects = TokenResource::class;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['data' => $this->collection];
    }
}
