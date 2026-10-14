<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\CreateDemoProperty;
use App\Domains\Tenancy\Actions\DeleteDemoProperty;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Creating a demo property, modelled on the property the form was opened in, and deleting one.
 */
class DemoPropertyController extends Controller
{
    public function store(Request $request, CreateDemoProperty $createDemoProperty): RedirectResponse
    {
        Gate::authorize('create', Property::class);
        /** @var User $user */
        $user = $request->user();
        $template = Tenancy::property() ?? abort(403);

        $property = $createDemoProperty->handle($user, $template);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added with sample data.', ['name' => $property->name])]);

        return redirect()->route('properties.index');
    }

    public function destroy(Request $request, Property $property, DeleteDemoProperty $deleteDemoProperty): RedirectResponse
    {
        Gate::authorize('delete', $property);
        /** @var User $user */
        $user = $request->user();

        $deleteDemoProperty->handle($user, $property);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $property->name])]);

        return redirect()->route('properties.index');
    }
}
