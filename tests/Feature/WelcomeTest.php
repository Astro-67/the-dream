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
