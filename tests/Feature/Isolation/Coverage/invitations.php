<?php

use App\Domains\Tenancy\Actions\SendInvitation;
use App\Domains\Tenancy\Data\InvitationDetails;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Notifications\InvitationSent;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for routes/web/invitations.php. See README.md.
 */

/**
 * A pending invitation to Bravo for someone outside both organizations, and the link from its email.
 *
 * @return array{0: Invitation, 1: string}
 */
$bravoInvitation = function (TwoTenants $t): array {
    Notification::fake();

    $invitation = inTenant($t->b->organization, fn () => app(SendInvitation::class)->handle($t->b->owner, InvitationDetails::fromArray([
        'email' => 'newcomer@bravo.test',
        'role' => 'front_desk',
        'property_ids' => [$t->b->property->id],
    ])));

    $link = '';
    Notification::assertSentOnDemand(InvitationSent::class, function (InvitationSent $notification) use (&$link) {
        $link = $notification->url();

        return true;
    });

    return [$invitation, $link];
};

return [
    // Bravo's pending invitations never appear in Alpha's list.
    'invitations.index' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInvitation) {
        $bravoInvitation($t);

        return [
            $this->actingAs($t->a->owner)->get(route('invitations.index'))->assertOk(),
            $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->get(route('invitations.index'))->assertOk(),
        ];
    }),

    // The form offers only Alpha's properties.
    'invitations.create' => RouteCoverage::visit(),

    // Inviting to a Bravo property is refused, and nothing is created in Bravo.
    'invitations.store' => RouteCoverage::attempt(function (TwoTenants $t) {
        $responses = [
            $this->actingAs($t->a->owner)
                ->post(route('invitations.store'), ['email' => 'spy@alpha.test', 'role' => 'front_desk', 'property_ids' => [$t->b->property->id]])
                ->assertSessionHasErrors('property_ids'),
            $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
                ->post(route('invitations.store'), ['email' => 'spy@alpha.test', 'role' => 'front_desk', 'property_ids' => [$t->b->property->id]])
                ->assertSessionHasErrors('property_ids'),
        ];

        expect(inTenant($t->b->organization, fn () => Invitation::query()->count()))->toBe(0);

        return $responses;
    }),

    // Revoking Bravo's invitation by ID is not found, and it stays pending.
    'invitations.destroy' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInvitation) {
        [$invitation] = $bravoInvitation($t);

        $response = $this->actingAs($t->a->owner)
            ->delete(route('invitations.destroy', $invitation))
            ->assertNotFound();

        expect(inTenant($t->b->organization, fn () => Invitation::query()->pending()->count()))->toBe(1);

        return $response;
    }),

    // A link is only found with its secret token: guessing one, or pairing Bravo's token with Alpha, is not found.
    'invitations.show' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInvitation) {
        [, $link] = $bravoInvitation($t);
        $token = basename($link);

        return [
            $this->actingAs($t->a->owner)
                ->get(route('invitations.show', ['organization' => $t->b->organization->id, 'token' => str_repeat('a', 48)]))
                ->assertNotFound(),
            $this->actingAs($t->a->owner)
                ->get(route('invitations.show', ['organization' => $t->a->organization->id, 'token' => $token]))
                ->assertNotFound(),
        ];
    }),

    // Accepting Bravo's invitation needs the invited email address; an Alpha member is refused and gains nothing.
    'invitations.accept' => RouteCoverage::attempt(function (TwoTenants $t) use ($bravoInvitation) {
        [, $link] = $bravoInvitation($t);

        $responses = [
            $this->actingAs($t->a->owner)
                ->post(route('invitations.accept', ['organization' => $t->b->organization->id, 'token' => str_repeat('a', 48)]))
                ->assertNotFound(),
            $this->actingAs($t->a->owner)->post($link)->assertForbidden(),
        ];

        expect(inTenant($t->b->organization, fn () => Invitation::query()->pending()->count()))->toBe(1);
        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->has('tenant.organizations', 1));

        return $responses;
    }),
];
