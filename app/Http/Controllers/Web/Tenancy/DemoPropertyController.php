<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\CreateDemoProperty;
use App\Domains\Tenancy\Actions\DeleteDemoProperty;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\DemoPropertyRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Creating a demo property, modelled on the property the form was opened in, and deleting one.
 */
class DemoPropertyController extends Controller
{
    public function store(DemoPropertyRequest $request, CreateDemoProperty $createDemoProperty): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $template = Tenancy::property() ?? abort(403);

        $property = $createDemoProperty->handle($user, $template);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added with sample data.', ['name' => $property->name])]);

        return redirect()->route('properties.index');
    }

    public function destroy(DemoPropertyRequest $request, Property $property, DeleteDemoProperty $deleteDemoProperty): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleteDemoProperty->handle($user, $property);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $property->name])]);

        return redirect()->route('properties.index');
    }
}
