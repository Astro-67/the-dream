<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Platform data: plans belong to no organization.
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->unsignedInteger('property_limit');
            $table->unsignedInteger('room_limit');
            $table->unsignedInteger('trial_days');
            $table->unsignedInteger('grace_days');
            $table->string('billing_period', 10);
            $table->unsignedBigInteger('price');
            $table->string('currency', 3);
            $table->json('modules');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained();
            $table->foreignUlid('plan_id')->constrained();
            $table->string('status', 12);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();
        });

        Schema::create('subscription_state_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('subscription_id')->constrained();
            $table->string('from', 12)->nullable();
            $table->string('to', 12);
            $table->string('reason');
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        // ponytail: placeholder commercial terms (PRD §13 says prices need validation); platform administrators edit them.
        $planId = (string) Str::ulid();
        DB::table('plans')->insert([
            'id' => $planId, 'name' => 'Essential', 'code' => 'ESSENTIAL',
            'property_limit' => 5, 'room_limit' => 150, 'trial_days' => 14, 'grace_days' => 14,
            'billing_period' => 'monthly', 'price' => 4900, 'currency' => 'USD', 'modules' => '[]',
            'is_default' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Existing organizations start a trial on the default plan.
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            $subscriptionId = (string) Str::ulid();
            DB::table('subscriptions')->insert([
                'id' => $subscriptionId, 'organization_id' => $organizationId, 'plan_id' => $planId, 'status' => 'trial',
                'trial_ends_at' => $now->copy()->addDays(14), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('subscription_state_changes')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'subscription_id' => $subscriptionId,
                'from' => null, 'to' => 'trial', 'reason' => 'Trial started for an existing organization', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_state_changes');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
