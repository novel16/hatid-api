<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Adds immutable intake pricing and append-only delivery evidence without changing old tables. */
    public function up(): void
    {
        /** Keep one receipt on its parcel; terminal outcomes never create a second receipt. */
        Schema::create('parcels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_address_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->char('submission_hash', 64);
            $table->string('description', 150);
            $table->string('note', 500)->nullable();
            $table->string('expected_method', 10);
            $table->string('fee_mode', 10);
            $table->unsignedBigInteger('quote');
            $table->unsignedBigInteger('fee');
            $table->unsignedBigInteger('customer_total');
            $table->unsignedBigInteger('seller_share');
            $table->string('override_reason', 400)->nullable();
            $table->string('status', 15)->default('pending');
            $table->timestamp('retry_at')->nullable();
            $table->string('receipt_method', 10)->nullable();
            $table->unsignedBigInteger('received_amount')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('receipt_reference', 100)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'operation_id']);
            $table->index(['user_id', 'status', 'batch_id']);
            $table->index(['user_id', 'customer_address_id']);
        });
        /** Each operation may append one historical outcome to a selected parcel. */
        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parcel_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->char('request_hash', 64);
            $table->string('outcome', 15);
            $table->string('note', 500);
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
            $table->unique(['parcel_id', 'operation_id']);
            $table->index('operation_id');
        });
    }

    /** An explicit rollback removes only this feature's tables. */
    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('parcels');
    }
};
