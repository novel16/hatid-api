<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /** Existing riders choose their vehicle without losing access or receiving a guessed default. */
        Schema::table('users', function (Blueprint $table): void {
            $table->string('vehicle', 20)->nullable();
        });
        /** Keep successful-delivery evidence and a version beside each independently saved address pin. */
        Schema::table('customer_addresses', function (Blueprint $table): void {
            $table->unsignedInteger('location_version')->default(0);
            $table->double('gps_accuracy')->nullable();
            $table->dateTime('gps_captured_at', 3)->nullable();
            $table->boolean('location_corrected')->default(false);
            $table->foreignId('verified_delivery_attempt_id')->nullable()->constrained('delivery_attempts')->nullOnDelete();
        });
        /** Small persistent hourly buckets survive cache clearing and serialize all riders' reservations. */
        Schema::create('map_usage', function (Blueprint $table): void {
            $table->dateTime('bucket')->primary();
            $table->unsignedInteger('units')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('map_usage');
        /** Remove only this feature's optional location metadata when explicitly rolled back. */
        Schema::table('customer_addresses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_delivery_attempt_id');
            $table->dropColumn(['location_version', 'gps_accuracy', 'gps_captured_at', 'location_corrected']);
        });
        /** Restore the previous profile schema without touching identity or tokens. */
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('vehicle');
        });
    }
};
