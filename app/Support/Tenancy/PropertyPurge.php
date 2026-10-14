<?php

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Removes every row a demo property owns: each table with a property_id column,
 * children before the parents they reference. Real properties are archived, never purged.
 *
 * Tables are read from the live schema, so a new property-owned table is covered without a change here.
 */
final class PropertyPurge
{
    public static function purge(Property $property): void
    {
        if (! $property->isDemo()) {
            throw new LogicException('Only a demo property is purged.');
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Purging a property requires the caller\'s transaction.');
        }

        $tables = array_values(array_filter(
            Schema::getTableListing(Schema::getCurrentSchemaName(), false),
            fn (string $table) => $table !== 'properties' && Schema::hasColumn($table, 'property_id'),
        ));
        $references = [];
        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['foreign_table'] === $table) {
                    // Self-references (a derived rate plan, a correcting posting) are cut first.
                    DB::table($table)->where('property_id', $property->id)->update(array_fill_keys($key['columns'], null));
                } elseif (in_array($key['foreign_table'], $tables, true)) {
                    $references[$table][$key['foreign_table']] = true;
                }
            }
        }

        while ($tables !== []) {
            $leaves = array_filter($tables, fn (string $table) => ! array_any($tables, fn (string $other) => isset($references[$other][$table])));
            if ($leaves === []) {
                throw new LogicException('Property-owned tables reference each other in a cycle: '.implode(', ', $tables));
            }
            foreach ($leaves as $table) {
                DB::table($table)->where('property_id', $property->id)->delete();
            }
            $tables = array_values(array_diff($tables, $leaves));
        }
    }
}
