<?php

namespace App\Http\Controllers\Web\Guests;

use App\Domains\Guests\Actions\MergeGuests;
use App\Domains\Guests\Models\Guest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\GuestMergeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GuestMergeController extends Controller
{
    public function create(Guest $guest): Response
    {
        Gate::authorize('merge', $guest);

        return Inertia::render('guests/merge', [
            'guest' => $guest->only(['id', 'name']),
            'candidates' => Guest::query()->where('property_id', $guest->property_id)->whereKeyNot($guest->id)->orderBy('name')->orderBy('id')->paginate(50)->withQueryString()->through(fn (Guest $candidate) => $candidate->only(['id', 'name', 'email', 'phone'])),
        ]);
    }

    public function __invoke(GuestMergeRequest $request, Guest $guest, MergeGuests $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $guest, $request->validated('duplicate_id'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guests merged.')]);

        return redirect()->route('guests.show', $guest);
    }
}
