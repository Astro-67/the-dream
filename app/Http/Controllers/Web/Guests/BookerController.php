<?php
namespace App\Http\Controllers\Web\Guests;
use App\Domains\Guests\Actions\CreateBooker;
use App\Domains\Guests\Actions\UpdateBooker;
use App\Domains\Guests\Models\Booker;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\BookerRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
class BookerController extends Controller {
    public function index(Request $request): Response {
        Gate::authorize('viewAny', Booker::class);
        return Inertia::render('bookers/index', [
            'canManage' => $request->user()?->can('create', Booker::class),
            'records' => Booker::query()->orderBy('name')->orderBy('id')->paginate(50)->withQueryString()->through(fn (Booker $booker) => ['id' => $booker->id, ...$booker->only(Booker::FIELDS)]),
        ]);
    }
    public function create(): Response {
        Gate::authorize('create', Booker::class);
        return Inertia::render('bookers/create');
    }
    public function store(BookerRequest $request, CreateBooker $action): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $booker = $action->handle($actor, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booker added.')]);
        return redirect()->route('bookers.show', $booker);
    }
    public function show(Booker $booker, Request $request): Response {
        Gate::authorize('view', $booker);
        return Inertia::render('bookers/show', ['record' => ['id' => $booker->id, ...$booker->only(Booker::FIELDS)], 'canManage' => $request->user()?->can('update', $booker)]);
    }
    public function edit(Booker $booker): Response {
        Gate::authorize('update', $booker);
        return Inertia::render('bookers/edit', ['record' => ['id' => $booker->id, ...$booker->only(Booker::FIELDS)]]);
    }
    public function update(BookerRequest $request, Booker $booker, UpdateBooker $action): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $booker, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booker saved.')]);
        return redirect()->route('bookers.show', $booker);
    }
}
