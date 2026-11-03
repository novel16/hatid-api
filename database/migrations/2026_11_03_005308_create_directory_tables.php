<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Adds private directories without changing existing application data. */
    public function up(): void
    {
        // A known phone identifies one customer per rider, including archives.
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('phone', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['user_id', 'phone']);
            $table->index(['user_id', 'name']);
        });

        // GPS stays nullable and read-only until an actual delivery authorizes a pin.
        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50);
            $table->string('text', 300)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // These preferences affect future parcel quotes, not stored price snapshots.
        Schema::create('sellers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('contact', 100)->nullable();
            $table->enum('mode', ['included', 'excluded']);
            $table->unsignedBigInteger('fee');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'name']);
        });
    }

    /** Removes only the directory tables if explicitly rolled back. */
    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('sellers');
    }
};
