<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\UpdatePropertyBranding;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\PropertyBrandingRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/** A property's branding, on the Branding tab of the property form. */
class PropertyBrandingController extends Controller
{
    public function __invoke(PropertyBrandingRequest $request, Property $property, UpdatePropertyBranding $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $update->handle($user, $property, $request->file('logo'), $request->boolean('remove_logo'), $request->validated('accent_colour'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Branding saved.')]);

        return back();
    }
}
