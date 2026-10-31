<?php

namespace App\Http\Controllers\Web\Platform;

use App\Domains\Tenancy\Actions\DeclineGoLive;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\DeclineGoLiveRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DeclineGoLiveController extends Controller
{
    public function __invoke(DeclineGoLiveRequest $request, string $property, DeclineGoLive $decline): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $decline->forPlatform($actor, $property, $request->validated('reason'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Go-live request declined.')]);

        return back();
    }
}
