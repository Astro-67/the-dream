<?php

namespace App\Http\Controllers\Web\Maintenance;

use App\Domains\Inventory\Models\Room;
use App\Domains\Maintenance\Actions\AssignTicket;
use App\Domains\Maintenance\Actions\CompleteTicket;
use App\Domains\Maintenance\Actions\ReportTicket;
use App\Domains\Maintenance\Enums\TicketCategory;
use App\Domains\Maintenance\Enums\TicketPriority;
use App\Domains\Maintenance\Models\Ticket;
use App\Domains\Maintenance\Models\TicketEvent;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Maintenance\AssignTicketRequest;
use App\Http\Requests\Maintenance\CompleteTicketRequest;
use App\Http\Requests\Maintenance\ReportTicketRequest;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Ticket::class);
        $view = in_array($request->query('view'), ['mine', 'done'], true) ? $request->query('view') : 'open';
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('maintenance/index', [
            'view' => $view,
            'tickets' => Ticket::query()->with(['room', 'assignee', 'reporter'])
                ->when($view === 'done', fn ($query) => $query->whereNotNull('completed_at')->orderByDesc('completed_at'))
                ->when($view !== 'done', fn ($query) => $query->whereNull('completed_at')->orderByDesc('created_at'))
                ->when($view === 'mine', fn ($query) => $query->where('assignee_id', $user->id))
                ->orderByDesc('id')->paginate(50)->withQueryString()->through(fn (Ticket $ticket) => $this->summary($ticket)),
            'rooms' => Room::query()->active()->orderBy('number')->get()->map(fn (Room $room) => $room->only(['id', 'number'])),
            'room' => $request->query('room'),
            'categories' => array_map(fn (TicketCategory $case) => ['value' => $case->value, 'label' => $case->label()], TicketCategory::cases()),
            'priorities' => array_map(fn (TicketPriority $case) => ['value' => $case->value, 'label' => $case->label()], TicketPriority::cases()),
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        Gate::authorize('view', $ticket);
        /** @var User $user */
        $user = $request->user();
        $canAssign = $user->can('assign', $ticket);
        $names = fn (array $ids) => User::query()->whereIn('id', array_filter($ids))->pluck('name', 'id');

        return Inertia::render('maintenance/show', [
            'ticket' => [...$this->summary($ticket), 'description' => $ticket->description],
            'canAssign' => $canAssign && $ticket->completed_at === null,
            'canComplete' => $user->can('complete', $ticket) && $ticket->completed_at === null,
            'assignees' => $canAssign ? $this->assignees() : [],
            'history' => $ticket->events()->with('actor')->orderBy('recorded_at')->orderBy('id')->get()->map(function (TicketEvent $event) use ($names) {
                $people = $names([$event->before['assignee_id'] ?? null, $event->after['assignee_id'] ?? null]);

                return [
                    'id' => $event->id, 'event' => $event->event,
                    'actor' => $event->actor->name ?? __('Former member'),
                    'from' => isset($event->before['assignee_id']) ? $people[$event->before['assignee_id']] ?? __('Former member') : null,
                    'to' => isset($event->after['assignee_id']) ? $people[$event->after['assignee_id']] ?? __('Former member') : null,
                    'recordedAt' => $event->recorded_at->toIso8601String(),
                ];
            }),
            'timezone' => Tenancy::property()?->timezone,
        ]);
    }

    public function store(ReportTicketRequest $request, ReportTicket $report): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{title: string, description: string, category: string, priority: string, room_id: string|null, location: string|null} $details */
        $details = [...['room_id' => null, 'location' => null], ...$request->validated()];
        $report->handle($user, $details);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Problem reported.')]);

        return back();
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assign): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $assign->handle($user, $ticket, $request->string('assignee_id')->toString());

        return back();
    }

    public function complete(CompleteTicketRequest $request, Ticket $ticket, CompleteTicket $complete): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $complete->handle($user, $ticket);

        return back();
    }

    /** @return array<string, mixed> */
    private function summary(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id, 'title' => $ticket->title,
            'category' => $ticket->category->label(), 'priority' => $ticket->priority->label(), 'priorityValue' => $ticket->priority->value,
            'place' => $ticket->place(), 'roomId' => $ticket->room_id,
            'assignee' => $ticket->assignee?->name, 'reportedBy' => $ticket->reporter->name ?? __('Former member'),
            'reportedAt' => $ticket->created_at->toIso8601String(), 'completedAt' => $ticket->completed_at?->toIso8601String(),
            'done' => $ticket->completed_at !== null,
        ];
    }

    /** @return list<array{id: string, name: string}> */
    private function assignees(): array
    {
        return OrganizationMembership::query()->with('user')->get()->map(fn (OrganizationMembership $member) => $member->user)
            ->filter(fn (User $user) => Access::allows($user, OrganizationPermission::MaintenanceReport, Tenancy::property()))
            ->sortBy('name')->values()->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all();
    }
}
