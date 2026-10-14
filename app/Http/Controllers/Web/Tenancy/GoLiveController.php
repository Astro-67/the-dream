<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\GoLive;
use App\Domains\Tenancy\Actions\MarkTaxesReviewed;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Taking a property live (store) and marking its taxes reviewed, the go-live gate with no data behind it.
 */
class GoLiveController extends Controller
{
    public function store(Request $request, Property $property, GoLive $goLive): RedirectResponse
    {
        Gate::authorize('goLive', $property);
        /** @var User $user */
        $user = $request->user();
        $goLive->handle($user, $property);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is live.', ['name' => $property->name])]);

        return back();
    }

    public function reviewTaxes(Request $request, Property $property, MarkTaxesReviewed $markTaxesReviewed): RedirectResponse
    {
        Gate::authorize('reviewTaxes', $property);
        /** @var User $user */
        $user = $request->user();
        $markTaxesReviewed->handle($user, $property);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Taxes marked as reviewed.')]);

        return back();
    }
}
