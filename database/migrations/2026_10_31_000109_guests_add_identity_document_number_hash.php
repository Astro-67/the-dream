<?php

use App\Domains\Guests\Models\IdentityDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_documents', function (Blueprint $table): void {
            $table->char('number_hash', 64)->nullable();
            $table->index(['number_hash', 'property_id'], 'identity_documents_number_hash_property_index');
        });

        DB::table('identity_documents')->select(['id', 'number'])->orderBy('id')->chunkById(500, function ($documents): void {
            foreach ($documents as $document) {
                DB::table('identity_documents')->where('id', $document->id)->update([
                    'number_hash' => IdentityDocument::numberHash($document->number === null ? null : Crypt::decryptString($document->number)),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('identity_documents', function (Blueprint $table): void {
            $table->dropIndex('identity_documents_number_hash_property_index');
            $table->dropColumn('number_hash');
        });
    }
};
