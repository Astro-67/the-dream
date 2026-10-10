<?php
namespace App\Http\Controllers\Web\Guests;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Actions\UpdateGuest;
use App\Domains\Guests\Models\Guest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\GuestRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
class GuestController extends Controller {
    public function index(Request $request): Response {
        Gate::authorize('viewAny', Guest::class);
        return Inertia::render('guests/index', [
            'canManage' => $request->user()?->can('create', Guest::class),
            'records' => Guest::query()->orderBy('name')->orderBy('id')->paginate(50)->withQueryString()->through(fn (Guest $guest) => ['id' => $guest->id, ...$guest->only(Guest::FIELDS)]),
        ]);
    }
    public function create(): Response {
        Gate::authorize('create', Guest::class);
        return Inertia::render('guests/create');
    }
    public function store(GuestRequest $request, CreateGuest $action): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $guest = $action->handle($actor, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guest added.')]);
        return redirect()->route('guests.show', $guest);
    }
    public function show(Guest $guest, Request $request): Response {
        Gate::authorize('view', $guest);
        return Inertia::render('guests/show', ['record' => ['id' => $guest->id, ...$guest->only(Guest::FIELDS)], 'canManage' => $request->user()?->can('update', $guest)]);
    }
    public function edit(Guest $guest): Response {
        Gate::authorize('update', $guest);
        return Inertia::render('guests/edit', ['record' => ['id' => $guest->id, ...$guest->only(Guest::FIELDS)]]);
    }
    public function update(GuestRequest $request, Guest $guest, UpdateGuest $action): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $guest, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guest saved.')]);
        return redirect()->route('guests.show', $guest);
    }
}
