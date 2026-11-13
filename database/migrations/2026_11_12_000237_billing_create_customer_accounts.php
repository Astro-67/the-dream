<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('customer_account_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->unique()->constrained();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->string('number', 30);
            $table->string('kind', 16);
            $table->string('customer_name');
            $table->string('paying_institution_type', 16)->nullable();
            $table->string('paying_institution_name')->nullable();
            $table->string('paying_institution_zrb_number', 100)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamps();
            $table->unique(['property_id', 'number']);
            $table->unique(['organization_id', 'property_id', 'id'], 'customer_account_tenant_unique');
        });
        Schema::table('folios', function (Blueprint $table) {
            $table->foreignUlid('reservation_id')->nullable()->change();
            $table->foreignUlid('customer_account_id')->nullable();
            $table->foreign(['organization_id', 'property_id', 'customer_account_id'], 'folio_account_tenant_fk')
                ->references(['organization_id', 'property_id', 'id'])->on('customer_accounts');
            $table->unique(['customer_account_id', 'currency']);
        });
        Schema::table('issued_documents', function (Blueprint $table) {
            $table->foreignUlid('customer_account_id')->nullable();
            $table->foreign(['organization_id', 'property_id', 'customer_account_id'], 'issued_document_account_tenant_fk')
                ->references(['organization_id', 'property_id', 'id'])->on('customer_accounts');
        });
        $check = 'CONSTRAINT folio_exactly_one_owner CHECK ((reservation_id IS NULL) <> (customer_account_id IS NULL))';
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE folios ADD $check");

            return;
        }

        $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'folios'")->sql;
        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'folios' AND sql IS NOT NULL");
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($sql, $indexes, $check) {
                $definition = preg_replace('/^CREATE TABLE ["`]?folios["`]?/i', 'CREATE TABLE folios_with_owner', $sql);
                DB::statement(substr(rtrim($definition), 0, -1).", $check)");
                DB::statement('INSERT INTO folios_with_owner SELECT * FROM folios');
                DB::statement('DROP TABLE folios');
                DB::statement('ALTER TABLE folios_with_owner RENAME TO folios');
                foreach ($indexes as $index) {
                    DB::statement($index->sql);
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        if (DB::table('folios')->whereNotNull('customer_account_id')->exists()) {
            throw new RuntimeException('Customer account folios must be retained; this migration cannot be rolled back with account data.');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE folios DROP CHECK folio_exactly_one_owner');
        }
        Schema::table('issued_documents', function (Blueprint $table) {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['organization_id', 'property_id', 'customer_account_id'] : 'issued_document_account_tenant_fk');
            $table->dropColumn('customer_account_id');
        });
        Schema::table('folios', function (Blueprint $table) {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['organization_id', 'property_id', 'customer_account_id'] : 'folio_account_tenant_fk');
            $table->dropUnique(['customer_account_id', 'currency']);
            $table->dropColumn('customer_account_id');
            $table->foreignUlid('reservation_id')->nullable(false)->change();
        });
        Schema::dropIfExists('customer_accounts');
        Schema::dropIfExists('customer_account_sequences');
    }
};
