<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\FiscalReceipting\Actions\CheckVfmsCredentials;
use App\Domains\FiscalReceipting\Actions\SaveVfmsCredentials;
use App\Domains\FiscalReceipting\Actions\SwitchVfmsMode;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalReceipting\VfmsSettingsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * A property's VFMS settings, on the Tax & ZRA tab of the property form.
 */
class VfmsSettingsController extends Controller
{
    public function update(VfmsSettingsRequest $request, Property $property, SaveVfmsCredentials $save): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $save->handle($user, $property, $request->string('integration_id')->value(), $request->string('registration_id')->value(), $request->input('token'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('VFMS credentials saved.')]);

        return back();
    }

    public function mode(VfmsSettingsRequest $request, Property $property, SwitchVfmsMode $switch): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $mode = VfmsMode::from($request->string('mode')->value());
        $switch->handle($user, $property, $mode, $request->string('reason')->value());
        Inertia::flash('toast', ['type' => 'success', 'message' => $mode === VfmsMode::Live ? __(':name files with VFMS Live.', ['name' => $property->name]) : __(':name is on VFMS Sandbox.', ['name' => $property->name])]);

        return back();
    }

    public function check(VfmsSettingsRequest $request, Property $property, CheckVfmsCredentials $check): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $outcome = $check->handle($user, $property)->zra_check;
        Inertia::flash('toast', match ($outcome) {
            'accepted' => ['type' => 'success', 'message' => __('Accepted by ZRA.')],
            'refused' => ['type' => 'error', 'message' => __('Refused by ZRA. Check the Integration ID and Token.')],
            default => ['type' => 'error', 'message' => __('Could not reach ZRA. Try again later.')],
        });

        return back();
    }
}
