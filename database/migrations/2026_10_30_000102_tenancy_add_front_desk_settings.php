<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedTinyInteger('star_rating')->nullable();
            $table->string('tin', 64)->nullable();
            $table->string('z_number', 64)->nullable();
            $table->string('vrn', 64)->nullable();
            $table->json('front_desk_settings')->nullable();
            $table->json('tax_settings')->nullable();
            $table->json('payment_settings')->nullable();
        });
        Schema::table('tax_rules', function (Blueprint $table) {
            $table->string('zra_tax_category', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tax_rules', fn (Blueprint $table) => $table->dropColumn('zra_tax_category'));
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn([
            'star_rating', 'tin', 'z_number', 'vrn', 'front_desk_settings', 'tax_settings', 'payment_settings',
        ]));
    }
};
