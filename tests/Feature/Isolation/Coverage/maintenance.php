<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Maintenance\Actions\ReportTicket;
use App\Domains\Maintenance\Models\Ticket;
use App\Domains\Maintenance\Models\TicketEvent;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

$bravoRoom = fn (TwoTenants $t) => inTenant($t->b->property, function () use ($t) {
    $type = app(CreateRoomType::class)->handle($t->b->owner, InventoryForm::roomType(['name' => 'Bravo Secret Suite', 'code' => 'BRVX']));

    return app(CreateRoom::class)->handle($t->b->owner, InventoryForm::room($type->id, ['number' => 'B-901']));
});

$bravoTicket = fn (TwoTenants $t) => inTenant($t->b->property, fn () => app(ReportTicket::class)->handle($t->b->owner, [
    'title' => 'Bravo secret leak', 'description' => 'Bravo private fault.', 'category' => 'repair', 'priority' => 'high', 'room_id' => null, 'location' => 'Bravo cellar',
]));

return [
    'maintenance.index' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoTicket) {
        $bravoTicket($t);
        $responses = [];
        foreach (['open', 'mine', 'done'] as $view) {
            $responses[] = $this->actingAs($t->a->owner)->get(route('maintenance.index', ['view' => $view]))->assertOk()->assertDontSee('Bravo secret leak')->assertDontSee('B-901');
        }

        return $responses;
    }),
    'maintenance.show' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoTicket) {
        $ticket = $bravoTicket($t);

        return $this->actingAs($t->a->owner)->get(route('maintenance.show', $ticket->id))->assertNotFound();
    }),
    'maintenance.store' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoRoom) {
        $room = $bravoRoom($t);
        $responses = [];
        foreach ([$t->a->property->id, $t->b->property->id] as $intent) {
            $response = $this->actingAs($t->a->owner)->post(route('maintenance.store'), ['_property' => $intent, 'title' => 'Attempt', 'description' => 'Attempt', 'category' => 'repair', 'priority' => 'low', 'room_id' => $room->id]);
            $intent === $t->a->property->id ? $response->assertSessionHasErrors('room_id') : $response->assertForbidden();
            $responses[] = $response;
        }
        expect(inTenant($t->b->property, fn () => Ticket::query()->count()))->toBe(0)
            ->and(inTenant($t->a->organization, fn () => Ticket::query()->count()))->toBe(0);

        return $responses;
    }),
    'maintenance.assign' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoTicket) {
        $ticket = $bravoTicket($t);
        $responses = [];
        foreach ([$t->a->property->id, $t->b->property->id] as $intent) {
            $responses[] = $this->actingAs($t->a->owner)->post(route('maintenance.assign', $ticket->id), ['_property' => $intent, 'assignee_id' => $t->a->member(OrganizationRole::Housekeeping)->id])
                ->assertStatus($intent === $t->a->property->id ? 404 : 403);
        }
        expect(inTenant($t->b->property, fn () => [$ticket->fresh()->assignee_id, TicketEvent::query()->count()]))->toBe([null, 1]);

        return $responses;
    }),
    'maintenance.complete' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoTicket) {
        $ticket = $bravoTicket($t);
        $responses = [];
        foreach ([$t->a->property->id, $t->b->property->id] as $intent) {
            $responses[] = $this->actingAs($t->a->owner)->post(route('maintenance.complete', $ticket->id), ['_property' => $intent])
                ->assertStatus($intent === $t->a->property->id ? 404 : 403);
        }
        expect(inTenant($t->b->property, fn () => [$ticket->fresh()->completed_at, TicketEvent::query()->count()]))->toBe([null, 1]);

        return $responses;
    }),
];
