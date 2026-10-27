<?php

use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;
use Tests\Support\VfmsHttp;

/** Bravo files one check-in with VFMS; its ZRA number is TEST-ACCOMMODATION-171. */
$bravoFiling = function (TwoTenants $t): void {
    test()->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    $t->b->property->update(['business_date' => '2026-12-10']);
    inTenant($t->b->property, function () use ($t) {
        $details = BookingForm::details($t->b->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($t->b->owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        $guest = app(CreateGuest::class)->handle($t->b->owner, ['name' => 'Bravo Filed Guest', 'documents' => [['type' => 'passport', 'number' => 'BRAVO-DOC-174', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        app(CheckIn::class)->handle($t->b->owner, $reservation);
    });
};

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
    'reports.index' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoFiling) {
        $bravoFiling($t);
        inTenant($t->b->property, function () use ($t) {
            $stay = Reservation::query()->firstOrFail();
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

            $filings = $this->get(route('reports.index', ['view' => 'filings', 'from' => '2026-12-10', 'to' => '2026-12-10']));
            if (in_array($role, [OrganizationRole::FrontDesk->value, OrganizationRole::Housekeeping->value], true)) {
                $filings->assertForbidden();
            } else {
                $filings->assertOk()->assertDontSee('TEST-ACCOMMODATION-171')->assertInertia(fn (Assert $page) => $page
                    ->where('filings.counts.0.count', 0)
                    ->where('filings.calls.data', []));
            }
            $responses[] = $filings;
        }

        return $responses;
    }),
    'reports.filings.csv' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoFiling) {
        $bravoFiling($t);
        $responses = [];
        foreach ($t->a->members() as $role => $member) {
            $response = $this->actingAs($member)->withSession([
                'tenancy.organization_id' => $t->b->organization->id,
                'tenancy.property_id' => $t->b->property->id,
            ])->get(route('reports.filings.csv', ['from' => '2026-12-10', 'to' => '2026-12-10']));
            if (in_array($role, [OrganizationRole::FrontDesk->value, OrganizationRole::Housekeeping->value], true)) {
                $response->assertForbidden();
            } else {
                $response->assertOk();
                expect(trim($response->getContent()))->toBe('"Business date",Reservation,Call,Currency,Amount,Status,"ZRA number"');
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
