<?php

namespace App\Http\Controllers\Web\Guests;

use App\Domains\Guests\Actions\MergeGuests;
use App\Domains\Guests\Models\Guest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\GuestMergeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GuestMergeController extends Controller
{
    public function __invoke(GuestMergeRequest $request, Guest $guest, MergeGuests $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $guest, $request->validated('duplicate_id'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guests merged.')]);

        return redirect()->route('guests.show', $guest);
    }
}
