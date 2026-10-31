<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\MarkTaxesReviewed;
use App\Domains\Tenancy\Actions\RequestGoLive;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\GoLiveRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Requesting go-live (store) and marking its taxes reviewed, the go-live gate with no data behind it.
 */
class GoLiveController extends Controller
{
    public function store(GoLiveRequest $request, Property $property, RequestGoLive $requestGoLive): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $requestGoLive->handle($user, $property);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Go-live requested for :name. Karafuu will review it.', ['name' => $property->name])]);

        return back();
    }

    public function reviewTaxes(GoLiveRequest $request, Property $property, MarkTaxesReviewed $markTaxesReviewed): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $markTaxesReviewed->handle($user, $property);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Taxes marked as reviewed.')]);

        return back();
    }
}
