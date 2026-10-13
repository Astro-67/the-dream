<?php

namespace App\Domains\FrontDesk;

use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
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
     * The business date of the property in context.
     */
    public static function current(): string
    {
        return self::of(Tenancy::property() ?? throw new LogicException('The business date requires a property in context.'));
    }
}
