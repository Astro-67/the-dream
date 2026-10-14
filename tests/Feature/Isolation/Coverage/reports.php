<?php

use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    'reports.index' => RouteCoverage::attempt(function (TwoTenants $t) {
        inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $role => $member) {
            $response = $this->actingAs($member)->withSession([
                'tenancy.organization_id' => $t->b->organization->id,
                'tenancy.property_id' => $t->b->property->id,
            ])->get(route('reports.index', ['from' => '2026-12-10', 'to' => '2026-12-11']));

            if (in_array($role, [OrganizationRole::FrontDesk->value, OrganizationRole::Housekeeping->value], true)) {
                $response->assertForbidden();
            } else {
                $response->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where('tenant.property.id', fn ($id) => in_array($id, [$t->a->property->id, $t->a->otherProperty->id], true))
                    ->where('kpis.soldRoomNights', 0)
                    ->where('kpis.currencies.0.bookedRoomValue', 0));
            }
            $responses[] = $response;
        }

        return $responses;
    }),
];
