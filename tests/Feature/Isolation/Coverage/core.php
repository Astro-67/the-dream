<?php

use Illuminate\Support\Facades\Storage;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for routes/web.php and framework routes. See README.md.
 */
return [
    'home' => RouteCoverage::visit(),

    'dashboard' => RouteCoverage::visit(),

    'up' => RouteCoverage::exempt('Health check; returns no data.'),

    // Files on the private local disk are served only with a signed URL, so a guessed path to Bravo's file is refused.
    'storage.local' => RouteCoverage::attempt(function (TwoTenants $t) {
        Storage::fake('local');
        $path = "organizations/{$t->b->organization->id}/exports/guests.csv";
        Storage::disk('local')->put($path, "guest,property\nJane,{$t->b->property->name}\n");

        return $this->actingAs($t->a->owner)->get("/storage/{$path}")->assertForbidden();
    }),

    'storage.local.upload' => RouteCoverage::attempt(function (TwoTenants $t) {
        Storage::fake('local');
        $path = "organizations/{$t->b->organization->id}/logo.png";
        Storage::disk('local')->put($path, 'original');

        $response = $this->actingAs($t->a->owner)
            ->call('PUT', "/storage/{$path}", content: 'overwritten')
            ->assertForbidden();

        expect(Storage::disk('local')->get($path))->toBe('original');

        return $response;
    }),
];
