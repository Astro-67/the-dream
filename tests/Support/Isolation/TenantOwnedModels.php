<?php

namespace Tests\Support\Isolation;

use App\Support\Tenancy\BelongsToProperty;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Finds tenant-owned models that are missing tenant scoping. A model is
 * tenant-owned when its table has organization_id, and property-owned when it
 * also has property_id (ADR 0008). Needs the migrated schema.
 */
final class TenantOwnedModels
{
    /**
     * Every concrete Eloquent model under app/.
     *
     * @return list<class-string<Model>>
     */
    public static function inApp(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    /**
     * One message per model that is tenant-owned but not scoped accordingly.
     *
     * @param  iterable<class-string<Model>>  $models
     * @return list<string>
     */
    public static function violations(iterable $models): array
    {
        $violations = [];

        foreach ($models as $class) {
            $table = (new $class)->getTable();
            $traits = class_uses_recursive($class);

            if (Schema::hasColumn($table, 'organization_id') && ! in_array(BelongsToTenant::class, $traits, true)) {
                $violations[] = "{$class} (table {$table}) has organization_id but does not use BelongsToTenant.";
            }

            if (Schema::hasColumn($table, 'property_id') && ! in_array(BelongsToProperty::class, $traits, true)) {
                $violations[] = "{$class} (table {$table}) has property_id but does not use BelongsToProperty.";
            }
        }

        return $violations;
    }
}
