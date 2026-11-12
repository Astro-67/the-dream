<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\IssuedDocuments;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Platform\Actions\AssignPlatformRole;
use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Reservations\Actions\AssignRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Subscriptions\Models\BillingDetails;
use App\Domains\Tenancy\Actions\CreateOrganization;
use App\Domains\Tenancy\Data\OrganizationSetup;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;
use Tests\Support\TwoTenants;
use Tests\Support\VfmsHttp;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A valid organization setup form submission. Override any field by key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function organizationSetupPayload(array $overrides = []): array
{
    return [
        'organization_name' => 'Spice Island Hotels',
        'country' => 'TZ',
        'property_name' => 'Stone Town Inn',
        'rooms' => 150,
        'property_type' => 'hotel',
        'address_line' => 'Kenyatta Road 12',
        'city' => 'Zanzibar City',
        'region' => 'Mjini Magharibi',
        'postal_code' => '71101',
        'phone' => '+255 24 223 0000',
        'email' => 'frontdesk@stonetowninn.example',
        'timezone' => 'Africa/Dar_es_Salaam',
        'currency' => 'USD',
        'jurisdiction_profile' => 'zanzibar',
        'zra_hotel_code' => 'ZH-0042',
        ...$overrides,
    ];
}

/**
 * An organization with its first property and owner, built through the same
 * action the setup form uses. The owner has two-factor authentication on, as
 * owner-only actions require (#13), unless one is passed in.
 *
 * @param  array<string, mixed>  $overrides  organization setup fields, as in organizationSetupPayload()
 * @return array{0: User, 1: Organization, 2: Property}
 */
function organizationWithOwner(array $overrides = [], ?User $owner = null): array
{
    $overrides += ['payment_settings' => ['use_cashier_shifts' => true]];
    $owner ??= User::factory()->withTwoFactor()->create();
    $organization = app(CreateOrganization::class)->handle($owner, OrganizationSetup::fromArray(organizationSetupPayload([
        'star_rating' => 3,
        'tin' => 'TIN-0042',
        'z_number' => 'Z-0042',
        'vrn' => 'VRN-0042',
        ...$overrides,
    ])));
    $property = inTenant($organization, fn () => Property::query()->sole());
    // Operational fixtures keep explicit tax arrangements; default seeding is tested through HTTP setup/creation.
    inTenant($property, function (): void {
        $rules = TaxRule::query()->pluck('id');
        Activity::query()->where('subject_type', TaxRule::class)->whereIn('subject_id', $rules)->delete();
        TaxRule::query()->delete();
    });
    // An operating hotel: tests of setup and go-live start from setupProperty().
    $property->forceFill(['status' => PropertyStatus::Live])->save();

    return [$owner, $organization, $property];
}

/**
 * Run a callback inside an organization's (or a property's) tenant context.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function inTenant(Organization|Property $tenant, Closure $callback): mixed
{
    $context = $tenant instanceof Property
        ? TenantContext::forProperty($tenant)
        : TenantContext::forOrganization($tenant);

    return Tenancy::within($context, $callback);
}

/** Saves complete VFMS credentials in Live mode, accepted by ZRA: the Zanzibar VFMS go-live gate. */
function vfmsLive(Property $property): void
{
    inTenant($property, fn () => VfmsCredential::query()->create(['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'token' => 'tok', 'mode' => VfmsMode::Live, 'zra_check' => 'accepted', 'zra_checked_at' => now()]));
}

/**
 * A member of platform staff, granted the role the way a seed or console command does.
 * With two-factor authentication enabled unless $twoFactor is false.
 *
 * @param  array<string, mixed>  $attributes
 */
function platformStaff(PlatformRole $role = PlatformRole::Administrator, bool $twoFactor = true, array $attributes = []): User
{
    $factory = User::factory();
    $user = ($twoFactor ? $factory->withTwoFactor() : $factory)->create($attributes);

    app(AssignPlatformRole::class)->handle($user, $role);

    return $user;
}

/**
 * Karafuu's billing details with one bank and one mobile-money account, which
 * issuing a SaaS invoice requires.
 *
 * @param  array<string, mixed>  $overrides
 */
function withBillingDetails(array $overrides = []): BillingDetails
{
    return BillingDetails::query()->create([
        'business_name' => 'Karafuu Ltd', 'address' => 'Mlandege Street, Zanzibar', 'phone' => '+255 777 000 111',
        'email' => 'billing@thedream.test', 'tin' => '100-200-300', 'vrn' => '40-001234-X',
        'accounts' => [
            ['type' => 'bank', 'bank' => 'CRDB Bank', 'branch' => 'Stone Town', 'swift' => 'CORUTZTZ', 'account_name' => 'Karafuu Ltd', 'account_number' => '0150123456700'],
            ['type' => 'mobile_money', 'provider' => 'M-Pesa', 'account_name' => 'Karafuu Ltd', 'account_number' => '0777000111'],
        ],
        ...$overrides,
    ]);
}

/**
 * The isolation fixture: organizations Alpha ($tenants->a) and Bravo ($tenants->b),
 * each with two properties and a member in every organization role. See
 * Tests\Support\TenantFixture for what each side holds.
 */
function twoTenants(): TwoTenants
{
    return TwoTenants::build();
}

/** Accepted evidence fixture, not a claim of a provider replacement-issuance procedure. */
/** $legacy: Live evidence from before the unit snapshot, whose answers name no business unit (production, Nov 2026). */
function correctionDocuments(TenantFixture $tenant, string $type = 'services_receipt', string $mode = 'live', bool $legacy = false): array
{
    return inTenant($tenant->property, function () use ($tenant, $type, $mode, $legacy) {
        $tenant->property->update(['email' => 'desk@example.test', 'phone' => '+255777123456', 'z_number' => 'Z0123456789-5940']);
        VfmsCredential::for($tenant->property)->fill(['mode' => VfmsMode::from($mode), 'unit_id' => 232, 'integration_id' => 'CORRECTION-ID', 'registration_id' => 'REG', 'token' => 'SECRET-TOKEN'])->save();
        $stay = app(CreateReservation::class)->handle($tenant->owner, BookingForm::details($tenant->owner));
        foreach (['OLD-232', 'NEW-232'] as $number) {
            // Each receipt belongs to its own charge: the corrected one is a later charge after reversing the wrong one.
            $message = DB::transaction(fn () => app(Outbox::class)->record('vfms', $stay, $type === 'services_receipt' ? 'services' : 'checkout', [
                'mode' => $mode, 'reservation_id' => $stay->id, 'posting_id' => 'charge-'.$number,
                'request_type' => $type === 'services_receipt' ? 'ACCOMMODATION_ADD_SERVICE' : 'ACCOMMODATION_CHECK_OUT',
                'path' => $type === 'services_receipt' ? 'service/' : 'guest/checkout/',
                'body' => $type === 'services_receipt'
                    ? ['currency' => 'USD', 'documentNumber' => 'PRIVATE-GUEST-ID', 'service' => [['price' => 10, 'quantity' => 1, 'discount' => 0]]]
                    : ['currency' => 'USD', 'documentNumberList' => [['documentNumber' => 'P1234567']]],
            ], 'fixture:'.$number));
            $report = json_decode(file_get_contents(__DIR__.'/Fixtures/vfms/checkout.json'), true)[0];
            // ZRA's test unit is 5940 (observed 5 Nov 2026); the property's own unit 232 is never the test server's.
            $business = ['businessName' => 'Accepted business', ...($legacy ? [] : ['businessUnitId' => 5940])];
            $response = $type === 'services_receipt' ? ['receiptNumber' => $number, ...$business] : [[...$report, 'reportNumber' => $number, ...$business]];
            $message->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'provider_references' => [
                'mode' => $mode, ...($legacy ? [] : ['unitId' => 232]),
                ...($type === 'services_receipt' ? ['receiptNumber' => $number] : ['reportNumbers' => json_encode([$number])]),
            ]])->save();
            $message->attempts()->create(['attempt_number' => 1, 'outcome' => 'accepted', 'request_body' => json_encode($message->payload), 'response_body' => json_encode(['status' => 200, 'body' => $response])]);
            app(IssuedDocuments::class)->project($message);
            test()->travel(1)->minutes();
        }

        return [$stay, IssuedDocument::query()->where('number', 'OLD-232')->sole(), IssuedDocument::query()->where('number', 'NEW-232')->sole()];
    });
}

/** Submits the fixture pair as its owner through the real route. */
function submitCorrection($test, TenantFixture $tenant, IssuedDocument $old, IssuedDocument $new)
{
    return $test->actingAs($tenant->owner)->post(route('issued-documents.corrections.store', $old), [
        '_property' => $tenant->property->id, 'replacement_id' => $new->id, 'reason' => 'Wrong particulars', 'confirmed' => true,
    ]);
}

/** Posts an extra through the real charge route as the owner. */
function postGeneralReceiptExtra($test, TenantFixture $tenant, Folio $folio, array $charge): Posting
{
    $test->actingAs($tenant->owner)->post(route('folios.charges.store', $folio), [
        '_property' => $tenant->property->id, 'category' => 'restaurant', 'description' => 'LUNCH', 'quantity' => 1, 'unit_price' => 1000,
        'idempotency_key' => (string) Str::ulid(), ...$charge,
    ])->assertSessionHasNoErrors();

    return inTenant($tenant->property, fn () => Posting::query()->where('folio_id', $folio->id)->where('type', 'charge')->where('category', '!=', 'room')->latest('posted_at')->orderByDesc('id')->firstOrFail());
}

/** A two-room government group, both rooms checked in, Anna Berg in the first room. */
function generalReceiptGroup(TenantFixture $tenant): array
{
    return inTenant($tenant->property, function () use ($tenant) {
        $form = BookingForm::details($tenant->owner);
        app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($form['room_type_id'], ['number' => '102']));
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        app(CreateReservation::class)->handle($tenant->owner, [
            ...$form, 'rooms' => 2, 'adults' => 2, 'children' => 0, 'distribute_guests' => true, 'nightly_rate' => 11000, 'meal_plan' => 'bed-and-breakfast',
            'paying_institution_type' => 'government', 'paying_institution_name' => 'Ministry of Health', 'paying_institution_zrb_number' => 'WA2400117',
        ]);
        $rooms = Room::query()->orderBy('number')->get();

        return Reservation::query()->orderBy('reservation_number')->get()->map(function (Reservation $member, int $index) use ($tenant, $rooms) {
            $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => ['Anna Berg', 'Ola Berg'][$index], 'documents' => [['type' => 'passport', 'number' => ['P1234567', 'P7654321'][$index], 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $member->id, 'guest_id' => $guest->id]);
            app(AssignRoom::class)->handle($tenant->owner, $member, $rooms[$index]->id);

            return app(CheckIn::class)->handle($tenant->owner, $member->fresh());
        })->all();
    });
}

/** Issues the stay's held extras through the real route as the owner. */
function issueGeneralReceiptAs($test, TenantFixture $tenant, Reservation $reservation)
{
    return $test->actingAs($tenant->owner)->post(route('reservations.general-receipt.store', $reservation), ['_property' => $tenant->property->id]);
}

/** One room, Anna Berg (P1234567) as lead guest; checked in unless $checkIn is false. */
function generalReceiptStay(TenantFixture $tenant, array $details = [], bool $checkIn = true): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $details, $checkIn) {
        $form = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [
            ...$form, 'adults' => 1, 'children' => 0, 'nightly_rate' => 11000,
            'room_id' => Room::query()->sole()->id, 'meal_plan' => 'bed-and-breakfast', ...$details,
        ]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Anna Berg', 'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return $checkIn ? app(CheckIn::class)->handle($tenant->owner, $reservation) : $reservation;
    });
}

/** Replaces the fake's service/ answer; Laravel uses the first matching stub, so a later Http::fake cannot override it. */
function fakeGeneralReceiptService(mixed $response): void
{
    Http::swap(new Factory);
    Http::fake([VfmsHttp::TEST_URL.'service/' => $response]);
    VfmsHttp::fake();
}
