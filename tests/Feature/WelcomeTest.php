<?php

use App\Domains\Subscriptions\Models\Plan;
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

it('uses current yearly pricing and configured contact details without an archived price fallback', function () {
    Plan::query()->firstOrFail()->update([
        'name' => 'Coastal',
        'price' => 12345,
        'currency' => 'EUR',
        'billing_period' => 'yearly',
        'trial_days' => 21,
    ]);
    config(['mail.from.address' => 'sales@example.test']);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('plans.0.name', 'Coastal')
            ->where('plans.0.price', 12345)
            ->where('plans.0.currency', 'EUR')
            ->where('plans.0.period', 'yearly')
            ->where('plans.0.trialDays', 21)
            ->where('contactEmail', 'sales@example.test'));
});

it('reflects an administrator plan edit on the next public request without exposing private plan configuration', function () {
    $plan = Plan::default();
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->has('plans', 1)->where('plans.0.price', 4900));

    $this->actingAs(platformStaff())
        ->put(route('platform.plans.update', $plan), [
            'name' => 'Coastal',
            'code' => $plan->code,
            'price' => 76543,
            'currency' => 'EUR',
            'billing_period' => 'yearly',
            'trial_days' => 0,
            'grace_days' => 7,
            'property_limit' => 8,
            'is_default' => true,
        ])
        ->assertRedirect(route('platform.plans.index'))
        ->assertSessionHasNoErrors();

    auth()->forgetGuards();
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', null)
            ->where('plans', [[
                'name' => 'Coastal',
                'price' => 76543,
                'currency' => 'EUR',
                'period' => 'yearly',
                'trialDays' => 0,
            ]]));
});

it('returns no invented offers when no plans are configured', function () {
    Plan::query()->delete();
    config(['mail.from.address' => 'sales@example.test']);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('plans', [])
            ->where('contactEmail', 'sales@example.test'));
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

it('ships the supplied hospitality photographs without changing their source bytes', function (
    string $filename,
    int $width,
    int $height,
    string $sha256,
) {
    $path = public_path("images/marketing/{$filename}.webp");
    $image = getimagesize($path);

    expect($image)->not->toBeFalse();
    expect($image[0])->toBe($width)
        ->and($image[1])->toBe($height)
        ->and($image[2])->toBe(IMAGETYPE_WEBP)
        ->and(filesize($path))->toBeLessThan(350000)
        ->and(hash_file('sha256', $path))->toBe($sha256);
})->with([
    ['coastal-reception-welcome', 1448, 1086, '724d428aa2e0c3c9868ad253537fb854ee1995859718efb5cda9bf67a056b5af'],
    ['coastal-prepared-room', 1448, 1086, 'bf35a88af99d13caf2623da7dba9bdb18e9d996f1ffe986d676813b42cc91fe8'],
    ['coastal-team-veranda', 1448, 1086, 'e56309f98f5a27d7884e0941dc3711664ed73311d7d23b2a101859c80b550cf3'],
    ['coastal-doorway-ocean-portrait', 1024, 1536, '13fcd5d490bd3867875cd1d313e7a72ffd6fb49cb1b6b0c53f23650f1b0c256d'],
]);
