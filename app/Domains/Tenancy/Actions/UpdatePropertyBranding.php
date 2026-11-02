<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sets, replaces or removes a property's logo and sets or clears its accent colour, and audits the change.
 * The new logo is stored before the property changes, so a refused or failed save keeps the previous branding.
 */
class UpdatePropertyBranding
{
    public function handle(User $actor, Property $property, ?UploadedFile $logo, bool $removeLogo, ?string $accentColour): Property
    {
        $disk = Storage::disk('local');
        $stored = null;
        if ($logo !== null) {
            $stored = $logo->store("property-logos/{$property->organization_id}/{$property->id}", 'local');
            if ($stored === false) {
                Audit::record($actor, 'refused', $property, 'Property branding refused', ['result' => 'refused', 'stage' => 'storage']);

                throw ValidationException::withMessages(['logo' => __('The logo could not be saved. Try again.')]);
            }
        }

        try {
            [$property, $previous] = DB::transaction(function () use ($actor, $property, $stored, $removeLogo, $accentColour) {
                $property = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
                $previous = $property->logo_path;
                $old = ['logo' => $previous !== null, 'accent_colour' => $property->accent_colour];
                $property->forceFill([
                    'logo_path' => $stored ?? ($removeLogo ? null : $previous),
                    'accent_colour' => $accentColour === null ? null : strtolower($accentColour),
                ])->save();
                Audit::record($actor, 'branding_updated', $property, 'Property branding updated', [
                    'property_id' => $property->id,
                    'old' => $old,
                    'attributes' => ['logo' => $property->logo_path !== null, 'logo_replaced' => $property->logo_path !== $previous, 'accent_colour' => $property->accent_colour],
                ]);

                return [$property, $previous];
            });
        } catch (Throwable $exception) {
            if (is_string($stored)) {
                $disk->delete($stored);
            }

            throw $exception;
        }

        if ($previous !== null && $previous !== $property->logo_path) {
            $disk->delete($previous);
        }

        return $property;
    }
}
