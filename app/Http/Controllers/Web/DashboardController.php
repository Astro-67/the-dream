<?php

namespace App\Http\Controllers\Web;

use App\Domains\Tenancy\OnboardingChecklist;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OnboardingChecklist $checklist): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $property = Tenancy::property();

        return Inertia::render('dashboard', [
            'onboarding' => $property === null ? null : $checklist->forProperty($property, $actor),
        ]);
    }
}
