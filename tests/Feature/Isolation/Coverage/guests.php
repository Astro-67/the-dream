<?php

use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

$coverage = [];
foreach (['guests' => Guest::class, 'bookers' => Booker::class] as $domain => $model) {
    foreach (['index', 'create'] as $page) {
        $coverage["$domain.$page"] = RouteCoverage::attempt(function (TwoTenants $t) use ($domain, $model, $page) {
            inTenant($t->b->property, fn () => $model::query()->create(['name' => 'Bravo Secret Contact', 'email' => 'secret@bravo.test']));
            $responses = [];
            foreach ($t->a->members() as $member) {
                $responses[] = $this->actingAs($member)->get(route("$domain.$page"))->assertDontSee('Bravo Secret Contact')->assertDontSee('secret@bravo.test');
            }

            return $responses;
        });
    }
    foreach (['show', 'edit', 'update'] as $page) {
        $coverage["$domain.$page"] = RouteCoverage::attempt(function (TwoTenants $t) use ($domain, $model, $page) {
            $record = inTenant($t->b->property, fn () => $model::query()->create(['name' => 'Bravo Secret Contact', 'email' => 'secret@bravo.test']));
            $before = $record->getAttributes();
            $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
            $response = $page === 'update'
                ? $this->put(route("$domain.$page", $record), ['_property' => $t->a->property->id, 'name' => 'Hijacked'])->assertNotFound()
                : $this->get(route("$domain.$page", $record))->assertNotFound();
            expect(inTenant($t->b->property, fn () => $record->fresh()->getAttributes()))->toEqual($before);

            return $response;
        });
    }
    $coverage["$domain.store"] = RouteCoverage::attempt(function (TwoTenants $t) use ($domain, $model) {
        $record = inTenant($t->b->property, fn () => $model::query()->create(['name' => 'Bravo Secret Contact']));
        $before = $record->getAttributes();
        $response = $this->actingAs($t->a->owner)->post(route("$domain.store"), ['_property' => $t->b->property->id, 'name' => 'Hijacked'])->assertForbidden();
        expect(inTenant($t->b->property, fn () => $model::query()->count()))->toBe(1);
        expect(inTenant($t->b->property, fn () => $record->fresh()->getAttributes()))->toEqual($before);

        return $response;
    });
}

return $coverage;
