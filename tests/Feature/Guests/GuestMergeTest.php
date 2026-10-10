<?php

use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Actions\MergeGuests;
use App\Domains\Guests\Models\Guest;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

test('property manager explicitly merges duplicate guests keeping stays and encrypted documents', function () {
    $t = twoTenants();
    [$survivor, $duplicate, $reservation] = inTenant($t->a->property, function () use ($t) {
        $survivor = app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Amina Said', 'documents' => [['type' => 'passport', 'number' => 'KEEP-SECRET']]]);
        $duplicate = app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Amina Duplicate', 'documents' => [['type' => 'passport', 'number' => 'MOVE-SECRET']]]);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        app(AttachReservationGuest::class)->handle($t->a->owner, $reservation, ['guest_id' => $duplicate->id]);

        return [$survivor, $duplicate, $reservation];
    });
    $actor = $t->a->member(OrganizationRole::PropertyManager);
    $this->actingAs($actor)->post('/guests/'.$survivor->id.'/merge', ['_property' => $t->a->property->id, 'duplicate_id' => $duplicate->id])
        ->assertSessionHasNoErrors()->assertRedirect(route('guests.show', $survivor));
    $this->get(route('guests.show', $duplicate))->assertNotFound();
    $this->get(route('guests.show', $survivor))->assertInertia(fn (Assert $page) => $page->where('record.name', 'Amina Said')->where('stays.total', 1)->where('stays.data.0.reservation_number', $reservation->reservation_number)->has('documents', 2)->where('documents.0.number', '••••'));
    $this->actingAs($t->a->owner)->get(route('guests.show', $survivor))->assertInertia(fn (Assert $page) => $page->where('documents', fn ($documents) => collect($documents)->pluck('number')->sort()->values()->all() === ['KEEP-SECRET', 'MOVE-SECRET']));
    $audit = Activity::query()->where('event', 'merged')->sole();
    expect($audit->causer_id)->toBe($actor->id);
    expect($audit->properties->get('surviving_guest'))->toEqual(['id' => $survivor->id, 'name' => 'Amina Said']);
    expect($audit->properties->get('duplicate_guest'))->toEqual(['id' => $duplicate->id, 'name' => 'Amina Duplicate']);
    expect(Activity::query()->get()->toJson())->not->toContain('KEEP-SECRET')->not->toContain('MOVE-SECRET');
    expect(inTenant($t->a->property, fn () => Guest::query()->count()))->toBe(1);
});

test('merge selection lists other guests at the current property without identity fields', function () {
    $t = twoTenants();
    $survivor = inTenant($t->a->property, fn () => Guest::query()->create(['name' => 'Survivor']));
    $duplicate = inTenant($t->a->property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Duplicate', 'documents' => [['type' => 'passport', 'number' => 'SELECTION-SECRET']]]));
    inTenant($t->a->otherProperty, fn () => Guest::query()->create(['name' => 'Foreign Guest']));
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->get('/guests/'.$survivor->id.'/merge')
        ->assertOk()->assertDontSee('SELECTION-SECRET')->assertDontSee('Foreign Guest')
        ->assertInertia(fn (Assert $page) => $page->component('guests/merge')->where('guest.id', $survivor->id)->where('candidates.total', 1)->where('candidates.data.0.id', $duplicate->id));
    $this->get(route('guests.show', $survivor))->assertInertia(fn (Assert $page) => $page->where('canMerge', true));
});

test('only property managers and above may select or merge duplicate guests', function (OrganizationRole $role) {
    $t = twoTenants();
    [$survivor, $duplicate] = inTenant($t->a->property, fn () => [Guest::query()->create(['name' => 'Survivor']), Guest::query()->create(['name' => 'Duplicate'])]);
    $allowed = in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager], true);
    $this->actingAs($t->a->member($role))->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->get(route('guests.merge.create', $survivor))->assertStatus($allowed ? 200 : 403);
    $this->post(route('guests.merge', $survivor), ['_property' => $t->a->property->id, 'duplicate_id' => $duplicate->id])->assertStatus($allowed ? 302 : 403);
    expect(inTenant($t->a->property, fn () => Guest::query()->count()))->toBe($allowed ? 1 : 2);
    expect(Activity::query()->where('event', $allowed ? 'merged' : 'refused')->count())->toBe(1);
})->with(OrganizationRole::cases());

test('merge refuses self foreign property guests stale duplicates and missing property intent', function () {
    $t = twoTenants();
    $survivor = inTenant($t->a->property, fn () => Guest::query()->create(['name' => 'Survivor']));
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    foreach ([$t->a->otherProperty, $t->b->property] as $property) {
        $foreign = inTenant($property, fn () => app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Foreign', 'documents' => [['type' => 'passport', 'number' => 'FOREIGN-SECRET']]]));
        $this->postJson(route('guests.merge', $survivor), ['_property' => $t->a->property->id, 'duplicate_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('duplicate_id')->assertDontSee('FOREIGN-SECRET');
        $this->postJson(route('guests.merge', $foreign), ['_property' => $t->a->property->id, 'duplicate_id' => $survivor->id])->assertNotFound();
        expect(inTenant($property, fn () => $foreign->fresh()->documents()->count()))->toBe(1);
    }
    $this->postJson(route('guests.merge', $survivor), ['_property' => $t->a->property->id, 'duplicate_id' => $survivor->id])->assertUnprocessable()->assertJsonValidationErrors('duplicate_id');
    $duplicate = inTenant($t->a->property, fn () => Guest::query()->create(['name' => 'Duplicate']));
    $this->postJson(route('guests.merge', $survivor), ['duplicate_id' => $duplicate->id])->assertUnprocessable()->assertJsonValidationErrors('_property');
    $this->post(route('guests.merge', $survivor), ['_property' => $t->a->property->id, 'duplicate_id' => $duplicate->id])->assertSessionHasNoErrors();
    $this->postJson(route('guests.merge', $survivor), ['_property' => $t->a->property->id, 'duplicate_id' => $duplicate->id])->assertUnprocessable()->assertJsonValidationErrors('duplicate_id');
    expect(Activity::query()->where('event', 'merged')->count())->toBe(1);
});

test('merge collapses shared stays and rolls back documents links deletion and audit together', function () {
    $t = twoTenants();
    [$survivor, $duplicate, $reservation] = inTenant($t->a->property, function () use ($t) {
        $survivor = Guest::query()->create(['name' => 'Survivor']);
        $duplicate = app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Duplicate', 'documents' => [['type' => 'passport', 'number' => 'ROLLBACK-SECRET']]]);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        foreach ([$survivor, $duplicate] as $guest) {
            app(AttachReservationGuest::class)->handle($t->a->owner, $reservation, ['guest_id' => $guest->id]);
        }
        expect(fn () => DB::transaction(function () use ($t, $survivor, $duplicate) {
            app(MergeGuests::class)->handle($t->a->owner, $survivor, $duplicate->id);
            throw new RuntimeException('Abort merge');
        }))->toThrow(RuntimeException::class, 'Abort merge');
        expect($duplicate->fresh()->documents()->count())->toBe(1);
        expect($survivor->fresh()->documents()->count())->toBe(0);
        expect(Activity::query()->where('event', 'merged')->count())->toBe(0);

        return [$survivor, $duplicate, $reservation];
    });
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->get(route('reservations.guests.create', $reservation))->assertInertia(fn (Assert $page) => $page->has('guests', 2));
    $this->post(route('guests.merge', $survivor), ['_property' => $t->a->property->id, 'duplicate_id' => $duplicate->id])->assertSessionHasNoErrors();
    $this->get(route('reservations.guests.create', $reservation))->assertInertia(fn (Assert $page) => $page->has('guests', 1)->where('guests.0.id', $survivor->id));
    $this->get(route('guests.show', $survivor))->assertInertia(fn (Assert $page) => $page->where('stays.total', 1)->where('documents.0.number', 'ROLLBACK-SECRET'));
});
