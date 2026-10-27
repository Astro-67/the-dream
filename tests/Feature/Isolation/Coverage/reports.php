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
    'payments.index' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, function () use ($t) {
            $reservation = app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));
            app(RecordPayment::class)->handle($t->b->owner, Folio::query()->sole(), ['method' => 'card', 'amount' => 700, 'currency' => 'USD']);

            return $reservation;
        });
        $responses = [];
        foreach ($t->a->members() as $role => $member) {
            foreach (['received', 'outstanding'] as $view) {
                $response = $this->actingAs($member)->withSession([
                    'tenancy.organization_id' => $t->b->organization->id,
                    'tenancy.property_id' => $t->b->property->id,
                ])->get(route('payments.index', ['view' => $view]));
                if (in_array($role, [OrganizationRole::FrontDesk->value, OrganizationRole::Housekeeping->value], true)) {
                    $response->assertForbidden();
                } else {
                    $response->assertOk()->assertInertia(fn (Assert $page) => $page
                        ->where($view === 'received' ? 'received.rows' : 'folios.data', []));
                }
                $responses[] = $response;
                $filtered = $this->getJson(route('payments.index', ['view' => $view, 'reservation' => $reservation->id]));
                if (in_array($role, [OrganizationRole::FrontDesk->value, OrganizationRole::Housekeeping->value], true)) {
                    $filtered->assertForbidden();
                } else {
                    $filtered->assertUnprocessable()->assertJsonValidationErrors('reservation');
                }
                $responses[] = $filtered;
            }
        }

        return $responses;
    }),
    'reports.index' => RouteCoverage::attempt(function (TwoTenants $t) {
        inTenant($t->b->property, function () use ($t) {
            $stay = app(CreateReservation::class)->handle($t->b->owner, BookingForm::details($t->b->owner));
            $stay->setAttribute('fiscal_exception_reason', $t->b->property->name)->save();
        });
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
                    ->where('finance.payments.rows', [])
                    ->where('fiscalExceptions', in_array($role, [OrganizationRole::Owner->value, OrganizationRole::PropertyManager->value, OrganizationRole::Finance->value], true) ? [] : null));
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
