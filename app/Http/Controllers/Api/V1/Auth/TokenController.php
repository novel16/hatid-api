<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ListTokensRequest;
use App\Http\Resources\Auth\TokenCollection;
use App\Services\Auth\TokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TokenController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function index(ListTokensRequest $request): TokenCollection
    {
        return new TokenCollection($this->tokens->paginate(
            $request->user(),
            $request->integer('per_page', 15),
        ));
    }

    public function destroy(Request $request, int $token): Response
    {
        $this->tokens->revokeOwned($request->user(), $token);

        return response()->noContent();
    }
}
