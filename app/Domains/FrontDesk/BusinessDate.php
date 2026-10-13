<?php

namespace App\Domains\FrontDesk;

use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A property's stored business date (Y-m-d). Only night audit moves it.
 */
class BusinessDate
{
    /**
     * @param  Model  $subject  the property, or any record with a property_id
     */
    public static function of(Model $subject): string
    {
        $property = $subject instanceof Property ? $subject : Property::query()->findOrFail($subject->getAttribute('property_id'));

        return $property->business_date->toDateString();
    }

    /**
     * Whether the property's local clock has passed the close time of its business date.
     * ponytail: a close time before noon closes the date on the next calendar day (02:00 closes last night);
     * from noon on it closes the same day. Add an explicit "closes next day" flag if a property needs otherwise.
     */
    public static function isDue(Property $property): bool
    {
        $closesAt = CarbonImmutable::parse("{$property->business_date->toDateString()} {$property->close_time}", $property->timezone);

        return ! now()->lt($property->close_time < '12:00' ? $closesAt->addDay() : $closesAt);
    }

    /**
     * The business date of the property in context.
     */
    public static function current(): string
    {
        return self::of(Tenancy::property() ?? throw new LogicException('The business date requires a property in context.'));
    }
}
