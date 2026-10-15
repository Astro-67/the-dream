<?php

namespace App\Http\Requests\Reports;

use App\Domains\Reports\PropertyKpis;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Collection;

/**
 * Group reports over the real properties the member may view reports for. Supplied
 * property IDs must all be among them; anything else (another organization, a demo
 * property, a property the member isn't assigned) is one refusal.
 */
class GroupReportRequest extends ReportRequest
{
    /** @var Collection<int, Property>|null */
    private ?Collection $available = null;

    public function authorize(): bool
    {
        $available = $this->available();
        $requested = $this->input('properties', []);

        return $available->isNotEmpty()
            && (! is_array($requested) || collect($requested)->every(fn ($id) => ! is_string($id) || $available->contains('id', $id)));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'properties' => ['sometimes', 'array', 'list'], 'properties.*' => ['string']];
    }

    /**
     * Real, active properties of the current organization the member may view reports for.
     *
     * @return Collection<int, Property>
     */
    public function available(): Collection
    {
        /** @var User $user */
        $user = $this->user();

        return $this->available ??= Tenancy::propertiesFor($user)
            ->filter(fn (Property $property) => ! $property->isDemo() && $user->can('viewProperty', [PropertyKpis::class, $property]))
            ->values();
    }

    /** @return Collection<int, Property> */
    public function properties(): Collection
    {
        $requested = $this->validated('properties', []);

        return $requested === [] ? $this->available() : $this->available()->whereIn('id', $requested)->values();
    }
}
