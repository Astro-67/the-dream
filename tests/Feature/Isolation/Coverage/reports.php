<?php

use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    'reports.index' => RouteCoverage::attempt(function (TwoTenants $t) {
        inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        inTenant($t->b->property, function () use ($t) {
            app(OpenCashierShift::class)->handle($t->b->owner, ['USD' => 0]);
            app(RecordPayment::class)->handle($t->b->owner, Folio::query()->firstOrFail(), ['method' => 'cash', 'amount' => 700, 'currency' => 'USD']);
        });
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
                    ->where('kpis.currencies.0.bookedRoomValue', 0)
                    ->where('finance.folios.data', [])
                    ->where('finance.payments.rows', []));
            }
            $responses[] = $response;
        }

        return $responses;
    }),
    'reports.group' => RouteCoverage::attempt(function (TwoTenants $t) {
        inTenant($t->b->property, fn () => app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner)));
        $responses = [];
        foreach ($t->a->members() as $role => $member) {
            // Bravo's tenant in the session, and Bravo's property IDs supplied directly.
            $session = ['tenancy.organization_id' => $t->b->organization->id, 'tenancy.property_id' => $t->b->property->id];
            $supplied = $this->actingAs($member)->withSession($session)
                ->get(route('reports.group', ['from' => '2026-12-10', 'to' => '2026-12-11', 'properties' => [$t->b->property->id, $t->b->otherProperty->id]]))
                ->assertForbidden();
            $responses[] = $supplied;

            $response = $this->actingAs($member)->withSession($session)->get(route('reports.group', ['from' => '2026-12-10', 'to' => '2026-12-11']));
            if (in_array($role, [OrganizationRole::FrontDesk->value, OrganizationRole::Housekeeping->value], true)) {
                $response->assertForbidden();
            } else {
                $response->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where('properties', fn ($properties) => collect($properties)->pluck('id')->diff([$t->a->property->id, $t->a->otherProperty->id])->isEmpty())
                    ->where('kpis.soldRoomNights', 0));
            }
            $responses[] = $response;
        }

        return $responses;
    }),
];
