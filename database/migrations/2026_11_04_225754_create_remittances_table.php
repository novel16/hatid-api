<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Adds append-only handovers and their exact per-parcel seller-share allocations. */
    public function up(): void
    {
        Schema::create('remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('amount');
            $table->string('method', 10);
            $table->string('reference', 200)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'operation_id']);
            $table->index(['user_id', 'seller_id', 'created_at']);
        });
        /** No separate model is needed for this immutable amount pivot. */
        Schema::create('remittance_allocations', function (Blueprint $table): void {
            $table->foreignId('remittance_id')->constrained()->restrictOnDelete();
            $table->foreignId('parcel_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->primary(['remittance_id', 'parcel_id']);
            $table->index('parcel_id');
        });
    }

    /** An explicit rollback removes only this feature's allocation and handover tables. */
    public function down(): void
    {
        Schema::dropIfExists('remittance_allocations');
        Schema::dropIfExists('remittances');
    }
};
