<?php

use Inertia\Testing\AssertableInertia as Assert;

it('prices the landing page from the published plans', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('plans.0.name', 'Essential')
            ->where('plans.0.price', 4900)
            ->where('plans.0.currency', 'USD')
            ->where('plans.0.period', 'monthly')
            ->where('plans.0.trialDays', 14)
            ->has('hasScreens'));
});

it('ships matching feature previews in both themes', function (string $screen) {
    foreach (['light', 'dark'] as $theme) {
        $path = public_path("images/marketing/screens/{$screen}-{$theme}.webp");

        expect(is_file($path))->toBeTrue();
        $image = getimagesize($path);
        expect($image)->not->toBeFalse();
        expect($image[0])->toBe(1600)
            ->and($image[1])->toBe(1000)
            ->and($image[2])->toBe(IMAGETYPE_WEBP)
            ->and(filesize($path))->toBeLessThan(350000);
    }
})->with(['front-desk', 'housekeeping', 'billing', 'team']);
