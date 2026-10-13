<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Support\Money\Currency;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BookingForm;
use Tests\TestCase;

class FolioBackfillTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_reservations_in_different_tenants_get_one_folio_in_their_rate_plan_currency(): void
    {
        $t = \twoTenants();
        $reservations = [];
        foreach ([[$t->a->property, $t->a->owner], [$t->b->property, $t->b->owner]] as [$property, $owner]) {
            $reservations[] = [$property, \inTenant($property, fn () => app(CreateReservation::class)->handle($owner, BookingForm::details($owner)))];
        }
        $migration = require database_path('migrations/2026_10_23_000001_billing_create_folios_table.php');
        Schema::withoutForeignKeyConstraints(function () use ($migration) {
            $migration->down();
            $migration->up();
        });
        foreach ($reservations as [$property, $reservation]) {
            \inTenant($property, function () use ($reservation) {
                $this->assertSame(Currency::USD, Folio::query()->where('reservation_id', $reservation->id)->sole()->currency);
            });
        }
    }
}
