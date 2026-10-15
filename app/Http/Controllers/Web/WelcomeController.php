<?php

namespace App\Http\Controllers\Web;

use App\Domains\Subscriptions\Models\Plan;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /** The public landing page, priced from the plans platform administrators publish (design.md, Marketing surfaces). */
    public function __invoke(): Response
    {
        return Inertia::render('welcome', [
            'plans' => Plan::query()->orderBy('price')->get()->map(fn (Plan $plan) => [
                'name' => $plan->name,
                'price' => $plan->price,
                'currency' => $plan->currency->value,
                'period' => $plan->billing_period->value,
                'propertyLimit' => $plan->property_limit,
                'roomLimit' => $plan->room_limit,
                'trialDays' => $plan->trial_days,
            ])->all(),
            'contactEmail' => config('mail.from.address'),
            // Product screenshots are taken from showcase data; the page hides their slots until they exist.
            'hasScreens' => file_exists(public_path('images/marketing/screens/dashboard-light.webp')),
        ]);
    }
}
