# Isolation coverage

Every registered route needs an entry here, or `IsolationCoverageTest` fails and names the route.
`RouteIsolationTest` then runs each entry. A member of organization **Alpha** attacks organization **Bravo**,
and Bravo's markers (IDs, names, emails, codes) must not appear in any response body or header.

## Adding coverage for a new route

Add one entry, keyed by route name, to the file for your domain. For example, `routes/web/tenancy.php`
goes in `Coverage/tenancy.php`. Create the file if it doesn't exist; it returns an array.
Unnamed routes are keyed by URI, but name your routes.

```php
<?php

use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

return [
    // A page or list with no route parameters: GET as every Alpha member, nothing of Bravo's may show.
    'reservations.index' => RouteCoverage::visit(),

    // Anything with an ID, an export, a download or a write: attack Bravo's record and assert the refusal.
    // $this is the test case. Return the response(s) so they're searched for Bravo's data too.
    'reservations.show' => RouteCoverage::attempt(function (TwoTenants $t) {
        $reservation = inTenant($t->b->property, fn () => Reservation::factory()->create());

        return $this->actingAs($t->a->owner)
            ->get(route('reservations.show', $reservation))
            ->assertNotFound();
    }),

    // The route never touches tenant data. The reason is required.
    'two-factor.qr-code' => RouteCoverage::exempt("Acts only on the signed-in user's own credentials."),
];
```

For writes (`POST`, `PATCH`, `DELETE`), also assert that Bravo's record is unchanged. Tenant forms that change data must include their opening property's `_property` intent, including deletes.

## The fixture

`twoTenants()` (in `tests/Pest.php`) builds `$t->a` (Alpha) and `$t->b` (Bravo), each a `Tests\Support\TenantFixture` with:

- `organization`, `property` and `otherProperty` (also available as `properties`);
- `owner`, and `member(OrganizationRole::FrontDesk)` for any role. Owner and group operations manager may act
  in both properties; every other role only in `property`;
- `markers()`, the strings that only that tenant's data contains.

Create a tenant's records inside its context: `inTenant($t->b->property, fn () => ...)`.
Use `RouteCoverage::assertNothingOf($t->b, $response)` in any other test to check a response for leaks.
