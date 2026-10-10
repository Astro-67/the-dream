<?php

namespace App\Http\Controllers\Web\Guests;

use App\Domains\Guests\GuestMatches;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\GuestMatchRequest;
use Illuminate\Http\JsonResponse;

class GuestMatchController extends Controller
{
    public function __invoke(GuestMatchRequest $request, GuestMatches $matches): JsonResponse
    {
        return response()->json(['matches' => $matches->find($request->validated())]);
    }
}
