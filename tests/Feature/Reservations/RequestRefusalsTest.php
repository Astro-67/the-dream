<?php

use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingForm;

test('sensitive form refusals are audited without submitted identity data', function (string $action, bool $authorized) {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $actor = $authorized ? $t->a->owner : $t->a->member(OrganizationRole::Housekeeping);
    $this->actingAs($actor)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    [$method, $route, $parameters] = match ($action) {
        'create' => ['postJson', 'reservations.store', []],
        'amend' => ['patchJson', 'reservations.amend', [$reservation]],
        'assign' => ['patchJson', 'reservations.room-assignment', [$reservation]],
        'cancel' => ['postJson', 'reservations.cancel', [$reservation]],
        'void' => ['postJson', 'reservations.void', [$reservation]],
        'guest' => ['postJson', 'guests.store', []],
        'attach' => ['postJson', 'reservations.guests.store', [$reservation]],
        'booker' => ['postJson', 'bookers.store', []],
    };
    $before = DB::table('activity_log')->where('event', 'refused')->count();
    $this->$method(route($route, $parameters), ['_property' => $t->a->property->id, 'documents' => [['number' => 'DO-NOT-AUDIT']]])->assertStatus($authorized ? 422 : 403);
    $audit = DB::table('activity_log')->where('event', 'refused')->latest('id')->first();
    expect(DB::table('activity_log')->where('event', 'refused')->count())->toBe($before + 1)
        ->and($audit->causer_id)->toBe($actor->id)
        ->and(json_decode($audit->properties, true))->toMatchArray(['result' => 'refused', 'organization_id' => $t->a->organization->id, 'property_id' => $t->a->property->id])
        ->and($audit->properties)->not->toContain('DO-NOT-AUDIT');
})->with(['create', 'amend', 'assign', 'cancel', 'void', 'guest', 'attach', 'booker'])->with([true, false]);
