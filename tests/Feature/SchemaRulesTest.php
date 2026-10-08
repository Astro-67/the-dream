<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/*
 * Schema rules SQLite accepts and MySQL rejects. CI runs MySQL; these fail fast on SQLite too.
 */

test('index names fit the MySQL 64-character identifier limit', function () {
    $long = [];

    foreach (Schema::getTables() as $table) {
        foreach (Schema::getIndexes($table['name']) as $index) {
            if (strlen($index['name']) > 64) {
                $long[] = "{$table['name']}: {$index['name']}";
            }
        }
    }

    expect($long)->toBeEmpty("Pass an explicit, shorter index name:\n".implode("\n", $long));
});

test('encrypted casts sit on text columns, not json (ciphertext is not valid JSON)', function () {
    $wrong = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_contains(file_get_contents($file->getPathname()), "'encrypted")) {
            continue;
        }
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(app_path()) + 1));
        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }
        $model = new $class;
        foreach ($model->getCasts() as $column => $cast) {
            if (str_starts_with($cast, 'encrypted') && in_array(Schema::getColumnType($model->getTable(), $column), ['json', 'jsonb'], true)) {
                $wrong[] = "{$model->getTable()}.{$column}";
            }
        }
    }

    expect($wrong)->toBeEmpty("Use a text column:\n".implode("\n", $wrong));
});
