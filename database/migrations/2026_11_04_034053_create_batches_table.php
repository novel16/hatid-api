<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds owned intake batches without changing existing account or directory records.
     */
    public function up(): void
    {
        // The exact date range is unique per rider, including concurrent submissions.
        Schema::create('batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
            $table->unique(['user_id', 'start_date', 'end_date']);
        });
    }

    /**
     * Removes only the batches table when explicitly rolled back.
     */
    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
