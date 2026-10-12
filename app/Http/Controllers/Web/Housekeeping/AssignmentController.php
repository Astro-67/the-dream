<?php

namespace App\Http\Controllers\Web\Housekeeping;

use App\Domains\Housekeeping\Actions\AssignRoom;
use App\Domains\Housekeeping\Actions\UnassignRoom;
use App\Domains\Housekeeping\Models\Assignment;
use App\Domains\Inventory\Models\Room;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\AssignmentRequest;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AssignmentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('supervise', Assignment::class);
        $date = $this->date($request);

        return Inertia::render('housekeeping/assignments', [
            'date' => $date,
            'assignments' => $this->rows($date, null),
            'rooms' => Room::query()->active()->orderBy('number')->get()->map(fn (Room $room) => $room->only(['id', 'number'])),
            'housekeepers' => OrganizationMembership::query()->with('user')->get()->map(fn (OrganizationMembership $member) => $member->user)
                ->filter(fn (User $user) => Access::allows($user, OrganizationPermission::HousekeepingManage, Tenancy::property()))->values()->map(fn (User $user) => $user->only(['id', 'name'])),
        ]);
    }

    public function own(Request $request): Response
    {
        Gate::authorize('viewOwn', Assignment::class);

        return Inertia::render('housekeeping/my-rooms', [
            'date' => $date = $this->date($request),
            'assignments' => $this->rows($date, $request->user()->id),
        ]);
    }

    public function complete(Request $request, Assignment $assignment, \App\Domains\Housekeeping\Actions\CompleteAssignment $complete): RedirectResponse
    {
        Gate::authorize('complete', $assignment);
        /** @var User $user */
        $user = $request->user();
        $complete->handle($user, $assignment);

        return back();
    }

    public function store(AssignmentRequest $request, AssignRoom $assign): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $assign->handle($user, Room::query()->findOrFail($request->validated('room_id')), $request->validated('housekeeper_id'), $request->validated('business_date'));

        return back();
    }

    public function destroy(Request $request, Assignment $assignment, UnassignRoom $unassign): RedirectResponse
    {
        Gate::authorize('delete', $assignment);
        /** @var User $user */
        $user = $request->user();
        $unassign->handle($user, $assignment);

        return back();
    }

    private function date(Request $request): string
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        // ponytail: local calendar date until Spec 7 supplies the open business date.
        return $validated['date'] ?? now(Tenancy::property()?->timezone)->toDateString();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function rows(string $date, ?string $housekeeperId): \Illuminate\Support\Collection
    {
        return Assignment::query()->where('business_date', $date)->when($housekeeperId, fn ($query) => $query->where('housekeeper_id', $housekeeperId))
            ->with(['room.roomType', 'housekeeper'])->orderBy('id')->get()->map(fn (Assignment $assignment) => [
                'id' => $assignment->id,
                'room' => $assignment->room->only(['id', 'number', 'floor']),
                'housekeeper' => $assignment->housekeeper->only(['id', 'name']),
                'completed' => $assignment->completed_at !== null,
            ]);
    }
}
