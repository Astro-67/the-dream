<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\ArchiveProperty;
use App\Domains\Tenancy\Actions\RestoreProperty;
use App\Domains\Tenancy\Exceptions\ArchiveRefused;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Archiving a property (store) and restoring it (destroy). Owners only.
 */
class ArchivedPropertyController extends Controller
{
    public function store(Request $request, Property $property, ArchiveProperty $archiveProperty): RedirectResponse
    {
        Gate::authorize('archive', $property);

        /** @var User $user */
        $user = $request->user();

        try {
            $archiveProperty->handle($user, $property);
        } catch (ArchiveRefused $refused) {
            return back()->withErrors(['archive' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name archived.', ['name' => $property->name])]);

        return redirect()->route('properties.index');
    }

    public function destroy(Request $request, Property $property, RestoreProperty $restoreProperty): RedirectResponse
    {
        Gate::authorize('restore', $property);

        /** @var User $user */
        $user = $request->user();

        $restoreProperty->handle($user, $property);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name restored.', ['name' => $property->name])]);

        return redirect()->route('properties.index');
    }
}
